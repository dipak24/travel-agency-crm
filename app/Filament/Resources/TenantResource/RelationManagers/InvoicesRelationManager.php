<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Resources\TenantInvoiceResource;
use App\Models\TenantInvoice;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'tenantInvoices';

    protected static ?string $title = 'Billing';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('invoice_no')
            ->columns([
                TextColumn::make('invoice_no')->label('Invoice'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'warning',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('total')->money(fn (TenantInvoice $record): string => $record->currency, divideBy: 100),
                TextColumn::make('balance_due')->label('Balance due')->state(fn (TenantInvoice $record): int => $record->balanceDue())->money(fn (TenantInvoice $record): string => $record->currency, divideBy: 100),
                TextColumn::make('due_date')->date('M j, Y')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('view')
                    ->label('Open')
                    ->url(fn (TenantInvoice $record): string => TenantInvoiceResource::getUrl('edit', ['record' => $record])),
            ])
            ->headerActions([
                Action::make('createInvoice')
                    ->label('New invoice')
                    ->url(fn (): string => TenantInvoiceResource::getUrl('create', ['tenant_id' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
