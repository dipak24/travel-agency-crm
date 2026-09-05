<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\PaymentResource\Pages;
use App\Models\Invoice;
use App\Models\Payment;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
                        $set('amount', $invoice->balanceDue());
                        $set('currency', $invoice->currency);
                    }
                }),
            TextInput::make('amount')->label('Amount (minor units)')->numeric()->integer()->minValue(0)->required(),
            TextInput::make('currency')->maxLength(3)->required()->default('USD'),
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
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice.invoice_no')->label('Invoice')->searchable()->sortable(),
                TextColumn::make('invoice.customer.name')->label('Customer')->searchable(),
                TextColumn::make('amount')->numeric()->sortable(),
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
