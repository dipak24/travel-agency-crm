<?php

namespace App\Filament\Tenant\Resources\BookingResource\RelationManagers;

use App\Models\Payment;
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
                TextColumn::make('amount')->money(fn (Payment $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('method')->badge()->color('gray'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => Payment::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => Payment::statusColor($state)),
                TextColumn::make('transaction_ref')->label('Reference')->placeholder('—'),
                TextColumn::make('paid_at')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->defaultSort('paid_at', 'desc');
    }
}
