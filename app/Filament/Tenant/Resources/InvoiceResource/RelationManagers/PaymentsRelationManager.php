<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('amount')
                ->label('Amount (minor units)')
                ->numeric()->integer()->minValue(0)->required()
                ->default(fn (): int => $this->getOwnerRecord()->balanceDue()),
            TextInput::make('currency')->maxLength(3)->required()
                ->default(fn (): string => $this->getOwnerRecord()->currency),
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
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_ref')
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
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
