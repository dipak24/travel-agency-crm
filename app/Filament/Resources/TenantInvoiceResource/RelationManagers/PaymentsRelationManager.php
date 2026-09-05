<?php

namespace App\Filament\Resources\TenantInvoiceResource\RelationManagers;

use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Support\TenantContext;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('amount')
                ->label('Amount (minor units)')
                ->numeric()->integer()->minValue(0)->required()
                ->default(fn (Get $get): int => $this->getOwnerRecord()->balanceDue()),
            Select::make('currency')->options([
                'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'AUD' => 'AUD',
                'CAD' => 'CAD', 'AED' => 'AED', 'INR' => 'INR', 'NPR' => 'NPR', 'JPY' => 'JPY',
            ])->required()->default(fn (): string => $this->getOwnerRecord()->currency),
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
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_ref')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScopes())
            ->columns([
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
                TextColumn::make('transaction_ref')->label('Reference')->placeholder('—'),
                TextColumn::make('paid_at')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()->using(fn (array $data): TenantPayment => $this->createPayment($data)),
            ])
            ->recordActions([
                EditAction::make()->using(fn (Model $record, array $data): Model => $this->updatePayment($record, $data)),
                DeleteAction::make(),
            ]);
    }

    private function createPayment(array $data): TenantPayment
    {
        /** @var TenantInvoice $invoice */
        $invoice = $this->getOwnerRecord();
        $tenantContext = app(TenantContext::class);
        $tenantContext->set($invoice->tenant()->withoutGlobalScopes()->firstOrFail());

        try {
            return $invoice->payments()->create($data);
        } finally {
            $tenantContext->clear();
        }
    }

    private function updatePayment(Model $record, array $data): Model
    {
        /** @var TenantInvoice $invoice */
        $invoice = $this->getOwnerRecord();
        $tenantContext = app(TenantContext::class);
        $tenantContext->set($invoice->tenant()->withoutGlobalScopes()->firstOrFail());

        try {
            $record->update($data);

            return $record;
        } finally {
            $tenantContext->clear();
        }
    }
}
