<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\InvoiceResource\Pages;
use App\Filament\Tenant\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Models\Invoice;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationLabel = 'Invoices';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('booking_id')
                ->relationship('booking', 'trip_name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('customer_id')
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('invoice_no')
                ->label('Invoice Number')
                ->readOnly()
                ->required(),
            TextInput::make('amount')
                ->label('Amount (minor units)')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
            TextInput::make('tax')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),
            TextInput::make('discount')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),
            TextInput::make('total')
                ->label('Total (minor units)')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
            Select::make('status')->options([
                'draft' => 'Draft',
                'issued' => 'Issued',
                'paid' => 'Paid',
                'partially_paid' => 'Partially paid',
                'overdue' => 'Overdue',
                'cancelled' => 'Cancelled',
            ])->required(),
            DatePicker::make('due_date')->label('Due date')->native(false),
            Section::make('Payments')
                ->visibleOn('edit')
                ->schema([
                    Placeholder::make('paid_display')->label('Amount paid')->content(fn (Invoice $record): string => (string) $record->paidAmount()),
                    Placeholder::make('balance_display')->label('Balance due')->content(fn (Invoice $record): string => (string) $record->balanceDue()),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('invoice_no')->label('Invoice')->searchable()->sortable(),
            TextColumn::make('booking.trip_name')->label('Booking')->searchable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('total')->label('Total')->numeric()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('due_date')->date()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
