<?php

namespace App\Filament\Tenant\Resources\BookingResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_ref')
            ->columns([
                TextColumn::make('invoice.invoice_no')->label('Invoice'),
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
            ->defaultSort('paid_at', 'desc');
    }
}
