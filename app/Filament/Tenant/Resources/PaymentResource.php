<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\PaymentResource\Pages;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationLabel = 'Payments';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('invoice_id')
                ->relationship('invoice', 'invoice_no')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    $invoice = Invoice::query()->find($get('invoice_id'));

                    if ($invoice) {
                        $set('amount', Money::toDecimal($invoice->balanceDue()));
                        $set('currency', $invoice->currency);
                    }
                }),
            MoneyInput::make('amount')->label('Amount')->minValue(0)->required(),
            TextInput::make('currency')->maxLength(3)->required()->default('USD'),
            Select::make('method')->options([
                'bank_transfer' => 'Bank transfer',
                'card' => 'Card',
                'cash' => 'Cash',
                'paypal' => 'PayPal',
                'gift_voucher' => 'Gift voucher',
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
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice.invoice_no')->label('Invoice')->searchable()->sortable(),
                TextColumn::make('invoice.customer.name')->label('Customer')->searchable(),
                TextColumn::make('amount')->money(fn (Payment $record): string => $record->currency, divideBy: 100)->sortable(),
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
                IconColumn::make('reconciled_at')->label('Reconciled')->boolean()
                    ->getStateUsing(fn (Payment $record): bool => $record->reconciled_at !== null),
            ])
            ->filters([
                TernaryFilter::make('reconciled_at')
                    ->label('Reconciliation')
                    ->trueLabel('Reconciled')
                    ->falseLabel('Unreconciled')
                    ->placeholder('All payments')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('reconciled_at'),
                        false: fn ($query) => $query->whereNull('reconciled_at'),
                    ),
            ])
            ->recordActions([
                Action::make('toggleReconciled')
                    ->label(fn (Payment $record): string => $record->reconciled_at ? 'Mark unreconciled' : 'Mark reconciled')
                    ->icon('heroicon-o-check-badge')
                    ->color('gray')
                    ->visible(fn (Payment $record): bool => $record->status === 'completed')
                    ->action(fn (Payment $record) => $record->update(['reconciled_at' => $record->reconciled_at ? null : now()])),
                Action::make('refund')
                    ->label('Refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Payment $record): bool => in_array($record->method, app(PaymentGatewayResolver::class)->keys(), true)
                        && $record->type !== 'refund'
                        && $record->status === 'completed'
                        && auth('tenant')->user()?->can('refund', $record))
                    ->action(function (Payment $record): void {
                        try {
                            app(PaymentGatewayResolver::class)->for($record->method)->refund($record);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Refund failed')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Refund recorded')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}
