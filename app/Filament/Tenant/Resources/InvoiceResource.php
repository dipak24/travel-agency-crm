<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\InvoiceResource\Pages;
use App\Filament\Tenant\Resources\InvoiceResource\RelationManagers\ItemsRelationManager;
use App\Filament\Tenant\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Notifications\InvoiceEmailed;
use App\Notifications\InvoicePaymentLink;
use App\Services\Mail\TenantMailer;
use App\Support\Money;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationLabel = 'Invoices';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('booking_id')
                ->relationship('booking', 'trip_name')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    $booking = Booking::query()->find($get('booking_id'));

                    if ($booking) {
                        $set('customer_id', $booking->customer_id);
                    }
                }),
            Select::make('customer_id')
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->createOptionForm(CustomerResource::quickCreateSchema())
                ->createOptionAction(fn (Action $action) => $action
                    ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Customer::class))
                    ->modalHeading('Add guest customer')),
            TextInput::make('invoice_no')
                ->label('Invoice number')
                ->readOnly()
                ->helperText('Auto-generated when the invoice is created.')
                ->hiddenOn('create'),
            MoneyInput::make('amount')
                ->label('Amount')
                ->minValue(0)
                ->required(),
            MoneyInput::make('tax')
                ->minValue(0)
                ->default(0),
            MoneyInput::make('discount')
                ->minValue(0)
                ->default(0),
            MoneyInput::make('total')
                ->label('Total')
                ->minValue(0)
                ->required(),
            Select::make('status')->options([
                'draft' => 'Draft',
                'issued' => 'Issued',
                'paid' => 'Paid',
                'partially_paid' => 'Partially paid',
                'overdue' => 'Overdue',
                'cancelled' => 'Cancelled',
            ])->required(),
            DatePicker::make('due_date')->label('Due date')->native(false),
            Section::make('Payments')
                ->visibleOn('edit')
                ->schema([
                    Placeholder::make('paid_display')->label('Amount paid')->content(fn (Invoice $record): string => Money::format($record->paidAmount(), $record->currency)),
                    Placeholder::make('balance_display')->label('Balance due')->content(fn (Invoice $record): string => Money::format($record->balanceDue(), $record->currency)),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('invoice_no')->label('Invoice')->searchable()->sortable(),
            TextColumn::make('booking.trip_name')->label('Booking')->searchable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('total')->label('Total')->money(fn (Invoice $record): string => $record->currency, divideBy: 100)->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('due_date')->date()->sortable(),
        ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('downloadPdf')
                        ->label('Download PDF')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (Invoice $record) {
                            $record->loadMissing(['tenant', 'customer', 'booking']);

                            try {
                                $output = Pdf::loadView('pdf.invoice', ['invoice' => $record])->output();
                            } catch (Throwable $e) {
                                Log::error('Invoice PDF generation failed.', ['invoice_id' => $record->id, 'message' => $e->getMessage()]);
                                Notification::make()->title('Could not generate the PDF')->danger()->send();

                                return null;
                            }

                            return response()->streamDownload(
                                fn () => print ($output),
                                "{$record->invoice_no}.pdf",
                            );
                        }),
                    Action::make('emailInvoice')
                        ->label('Email invoice')
                        ->icon('heroicon-o-envelope')
                        ->requiresConfirmation()
                        ->modalDescription(fn (Invoice $record): string => "Email a PDF copy of this invoice to {$record->customer?->email}?")
                        ->action(function (Invoice $record): void {
                            $sent = app(TenantMailer::class)->send($record->tenant_id, $record->customer, new InvoiceEmailed($record));

                            if (! $sent) {
                                Notification::make()->title('Invoice failed to send')->body('Check the tenant\'s mail settings.')->danger()->send();

                                return;
                            }

                            Notification::make()->title('Invoice emailed')->success()->send();
                        }),
                    Action::make('sendPaymentLink')
                        ->label('Send payment link')
                        ->icon('heroicon-o-link')
                        ->visible(fn (Invoice $record): bool => $record->balanceDue() > 0 && $record->customer?->email !== null)
                        ->requiresConfirmation()
                        ->modalDescription(fn (Invoice $record): string => "Email a no-login payment link for this invoice to {$record->customer?->email}?")
                        ->action(function (Invoice $record): void {
                            $url = URL::temporarySignedRoute('public.pay.show', now()->addDays(14), ['invoice' => $record->id]);

                            $sent = app(TenantMailer::class)->send($record->tenant_id, $record->customer, new InvoicePaymentLink($record, $url));

                            if (! $sent) {
                                Notification::make()->title('Payment link failed to send')->body('Check the tenant\'s mail settings.')->danger()->send();

                                return;
                            }

                            Notification::make()->title('Payment link sent')->success()->send();
                        }),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
