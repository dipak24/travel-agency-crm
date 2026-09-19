<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\RelationManagers;

use App\Filament\Forms\Components\MoneyInput;
use App\Models\InvoiceItem;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('qty')
                ->numeric()->integer()->minValue(1)->required()->default(1)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('total', round(((float) $get('qty')) * ((float) $get('unit_price')), 2))),
            MoneyInput::make('unit_price')
                ->label('Unit price')
                ->minValue(0)->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('total', round(((float) $get('qty')) * ((float) $get('unit_price')), 2))),
            MoneyInput::make('total')
                ->minValue(0)->required(),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        $currency = fn (): string => $this->getOwnerRecord()->currency;

        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('description')->searchable(),
                TextColumn::make('qty')->label('Qty')->numeric(),
                TextColumn::make('unit_price')->label('Unit price')->money($currency, divideBy: 100),
                TextColumn::make('total')->money($currency, divideBy: 100),
                TextColumn::make('recipient_name')
                    ->label('Gift recipient')
                    ->state(fn (InvoiceItem $record): string => $record->recipientName() ?? '—')
                    ->searchable(['recipient_first_name', 'recipient_last_name', 'recipient_email']),
                TextColumn::make('recipient_email')->label('Recipient email')->placeholder('—'),
                TextColumn::make('recipient_phone')->label('Recipient phone')->placeholder('—'),
            ])
            ->defaultSort('id')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
