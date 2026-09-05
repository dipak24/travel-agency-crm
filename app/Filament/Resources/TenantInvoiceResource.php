<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantInvoiceResource\Pages;
use App\Filament\Resources\TenantInvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Models\TenantInvoice;
use App\Models\TenantSubscription;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TenantInvoiceResource extends Resource
{
    protected static ?string $model = TenantInvoice::class;

    protected static ?string $navigationLabel = 'Invoices';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = -1;

    /**
     * @return array<string, string>
     */
    public static function itemTypeOptions(): array
    {
        return [
            'subscription' => 'Subscription',
            'hosting' => 'Hosting',
            'domain' => 'Domain',
            'service_fee' => 'Service fee',
            'setup_fee' => 'Setup fee',
            'other' => 'Other',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Invoice')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('tenant_id')
                            ->relationship('tenant', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),
                        Select::make('status')->options([
                            'draft' => 'Draft',
                            'issued' => 'Issued',
                            'paid' => 'Paid',
                            'partially_paid' => 'Partially paid',
                            'overdue' => 'Overdue',
                            'cancelled' => 'Cancelled',
                        ])->required()->default('draft'),
                        Select::make('tenant_subscription_id')
                            ->label('Subscription (optional)')
                            ->helperText('Links this invoice to the billing cycle it covers.')
                            ->options(function (Get $get): array {
                                $tenantId = $get('tenant_id');

                                if (blank($tenantId)) {
                                    return [];
                                }

                                return TenantSubscription::query()->withoutGlobalScopes()
                                    ->where('tenant_id', $tenantId)
                                    ->with('plan')
                                    ->get()
                                    ->mapWithKeys(fn (TenantSubscription $subscription): array => [
                                        $subscription->id => sprintf('#%d — %s (%s)', $subscription->id, $subscription->plan?->name ?? 'Plan', $subscription->status),
                                    ])->all();
                            })
                            ->searchable(),
                        TextInput::make('invoice_no')
                            ->label('Invoice number')
                            ->readOnly()
                            ->helperText('Auto-generated when the invoice is created.')
                            ->hiddenOn('create'),
                        Select::make('currency')->options([
                            'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'AUD' => 'AUD',
                            'CAD' => 'CAD', 'AED' => 'AED', 'INR' => 'INR', 'NPR' => 'NPR', 'JPY' => 'JPY',
                        ])->required()->default('USD'),
                        DatePicker::make('issue_date')->native(false)->required()->default(now()->toDateString()),
                        DatePicker::make('due_date')->native(false),
                        TextInput::make('tax')->label('Tax (minor units)')->numeric()->integer()->minValue(0)->default(0),
                        TextInput::make('discount')->label('Discount (minor units)')->numeric()->integer()->minValue(0)->default(0),
                        Textarea::make('notes'),
                    ])
                    ->columns(2),
                Section::make('Totals')
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('subtotal_display')->label('Subtotal')->content(fn (TenantInvoice $record): string => (string) $record->subtotal),
                        Placeholder::make('total_display')->label('Total')->content(fn (TenantInvoice $record): string => (string) $record->total),
                        Placeholder::make('paid_display')->label('Amount paid')->content(fn (TenantInvoice $record): string => (string) $record->paidAmount()),
                        Placeholder::make('balance_display')->label('Balance due')->content(fn (TenantInvoice $record): string => (string) $record->balanceDue()),
                    ])
                    ->columns(2),
                Section::make('Line items')
                    ->columnSpanFull()
                    ->description('Subtotal and total are recalculated automatically from these lines when you save.')
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('type')->options(self::itemTypeOptions())->required()->default('other'),
                                TextInput::make('description')->required()->columnSpan(2),
                                TextInput::make('qty')->numeric()->integer()->minValue(1)->default(1)->required(),
                                TextInput::make('unit_price')->label('Unit price (minor units)')->numeric()->integer()->minValue(0)->default(0)->required(),
                            ])
                            ->columns(5)
                            ->defaultItems(0)
                            ->addActionLabel('Add line item')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['tenant', 'items']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_no')->label('Invoice')->searchable()->sortable(),
                TextColumn::make('tenant.name')->label('Tenant')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'warning',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('total')->numeric()->sortable(),
                TextColumn::make('balance_due')->label('Balance due')->state(fn (TenantInvoice $record): int => $record->balanceDue()),
                TextColumn::make('due_date')->date('M j, Y')->sortable()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('issue')
                        ->label('Mark issued')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('info')
                        ->requiresConfirmation()
                        ->visible(fn (TenantInvoice $record): bool => $record->status === 'draft')
                        ->action(fn (TenantInvoice $record) => $record->update(['status' => 'issued'])),
                    Action::make('cancel')
                        ->label('Cancel')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (TenantInvoice $record): bool => ! in_array($record->status, ['paid', 'cancelled'], true))
                        ->action(fn (TenantInvoice $record) => $record->update(['status' => 'cancelled'])),
                    Action::make('downloadPdf')
                        ->label('Download PDF')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (TenantInvoice $record) {
                            $record->loadMissing(['tenant', 'items']);

                            return response()->streamDownload(
                                fn () => print (Pdf::loadView('pdf.tenant-invoice', ['invoice' => $record])->output()),
                                "{$record->invoice_no}.pdf",
                            );
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
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantInvoices::route('/'),
            'create' => Pages\CreateTenantInvoice::route('/create'),
            'edit' => Pages\EditTenantInvoice::route('/{record}/edit'),
        ];
    }
}
