<?php

namespace App\Filament\Portal\Resources;

use App\Filament\Portal\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
                TextColumn::make('invoice_no')->label('Invoice')->searchable()->sortable(),
                TextColumn::make('booking.trip_name')->label('Booking'),
                TextColumn::make('total')->label('Total')->numeric()->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'overdue' => 'danger',
                        'partially_paid' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('due_date')->date()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->schema([
                    TextEntry::make('invoice_no')->label('Invoice number'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('due_date')->date(),
                    TextEntry::make('amount')->label('Subtotal'),
                    TextEntry::make('tax'),
                    TextEntry::make('discount'),
                    TextEntry::make('total'),
                    TextEntry::make('balance')->label('Balance due')->state(fn (Invoice $record): int => $record->balanceDue()),
                ])
                ->columns(4),
            Section::make('Items')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            TextEntry::make('description'),
                            TextEntry::make('qty')->label('Qty'),
                            TextEntry::make('unit_price')->label('Unit price'),
                            TextEntry::make('total'),
                        ])
                        ->columns(4)
                        ->contained(false),
                ])
                ->visible(fn (Invoice $record): bool => $record->items->isNotEmpty()),
            Section::make('Payments')
                ->schema([
                    RepeatableEntry::make('payments')
                        ->label('')
                        ->schema([
                            TextEntry::make('amount'),
                            TextEntry::make('method')->badge(),
                            TextEntry::make('type')->badge(),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('paid_at')->label('Paid at')->dateTime(),
                        ])
                        ->columns(5)
                        ->contained(false),
                ])
                ->visible(fn (Invoice $record): bool => $record->payments->isNotEmpty()),
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
