<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\TenantPaymentResource\Pages;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TenantPaymentResource extends Resource
{
    protected static ?string $model = TenantPayment::class;

    protected static ?string $navigationLabel = 'Transactions';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static UnitEnum|string|null $navigationGroup = 'Billing';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Transaction')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('tenant_invoice_id')
                            ->label('Invoice')
                            ->options(fn (): array => TenantInvoice::query()->withoutGlobalScopes()
                                ->with('tenant')
                                ->get()
                                ->mapWithKeys(fn (TenantInvoice $invoice): array => [
                                    $invoice->id => sprintf('%s — %s', $invoice->invoice_no, $invoice->tenant?->name),
                                ])->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                $invoice = TenantInvoice::query()->withoutGlobalScopes()->find($get('tenant_invoice_id'));

                                if ($invoice) {
                                    $set('amount', Money::toDecimal($invoice->balanceDue()));
                                    $set('currency', $invoice->currency);
                                }
                            }),
                        MoneyInput::make('amount')->label('Amount')->minValue(0)->required(),
                        Select::make('currency')->options([
                            'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'AUD' => 'AUD',
                            'CAD' => 'CAD', 'AED' => 'AED', 'INR' => 'INR', 'NPR' => 'NPR', 'JPY' => 'JPY',
                        ])->required()->default('USD'),
                        Select::make('method')->options([
                            'bank_transfer' => 'Bank transfer',
                            'card' => 'Card',
                            'cash' => 'Cash',
                            'paypal' => 'PayPal',
                            'other' => 'Other',
                        ])->required(),
                        Select::make('type')->options([
                            'advance' => 'Advance',
                            'installment' => 'Installment',
                            'final' => 'Final',
                            'refund' => 'Refund',
                        ])->required()->default('installment'),
                        Select::make('status')->options([
                            'pending' => 'Pending',
                            'completed' => 'Completed',
                            'failed' => 'Failed',
                            'refunded' => 'Refunded',
                        ])->required()->default('completed'),
                        TextInput::make('transaction_ref')->label('Transaction reference')->maxLength(255),
                        DateTimePicker::make('paid_at')->default(now()),
                        Textarea::make('notes'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['invoice.tenant']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice.invoice_no')->label('Invoice')->searchable()->sortable(),
                TextColumn::make('invoice.tenant.name')->label('Tenant')->searchable(),
                TextColumn::make('amount')->money(fn (TenantPayment $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('method')->badge()->color('gray'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'refunded' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('paid_at')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantPayments::route('/'),
            'create' => Pages\CreateTenantPayment::route('/create'),
            'edit' => Pages\EditTenantPayment::route('/{record}/edit'),
        ];
    }
}
