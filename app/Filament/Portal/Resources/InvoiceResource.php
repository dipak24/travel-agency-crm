<?php

namespace App\Filament\Portal\Resources;

use App\Filament\Portal\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use BackedEnum;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationLabel = 'Invoices';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('customer_id', auth('customer')->id())
            ->where('status', '!=', 'draft');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_no')->label('Invoice number')->searchable()->sortable(),
                TextColumn::make('purpose')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'gift_voucher_purchase' ? 'warning' : 'gray')
                    ->formatStateUsing(fn (?string $state): string => $state === 'gift_voucher_purchase' ? 'Gift voucher' : 'Standard'),
                TextColumn::make('booking.trip_name')->label('Booking')->placeholder('—'),
                TextColumn::make('created_at')->label('Date')->date()->sortable(),
                TextColumn::make('total')->label('Amount')->money(fn (Invoice $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('mode')
                    ->label('Mode')
                    ->state(fn (Invoice $record): ?string => $record->latestPaymentMethod())
                    ->formatStateUsing(fn (?string $state): string => $state ? Str::headline($state) : '—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'overdue' => 'danger',
                        'partially_paid' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('due_date')->date()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('purpose')
                    ->label('Type')
                    ->options([
                        'gift_voucher_purchase' => 'Gift voucher',
                        'standard' => 'Standard',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'gift_voucher_purchase' => $query->where('purpose', 'gift_voucher_purchase'),
                            'standard' => $query->where(fn (Builder $q) => $q->whereNull('purpose')->orWhere('purpose', '!=', 'gift_voucher_purchase')),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('detail')
                ->view('filament.invoices.detail')
                ->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
