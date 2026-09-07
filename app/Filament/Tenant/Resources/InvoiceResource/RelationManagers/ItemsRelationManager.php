<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\RelationManagers;

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
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('total', (int) $get('qty') * (int) $get('unit_price'))),
            TextInput::make('unit_price')
                ->label('Unit price (minor units)')
                ->numeric()->integer()->minValue(0)->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('total', (int) $get('qty') * (int) $get('unit_price'))),
            TextInput::make('total')
                ->label('Total (minor units)')
                ->numeric()->integer()->minValue(0)->required(),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('description')->searchable(),
                TextColumn::make('qty')->label('Qty')->numeric(),
                TextColumn::make('unit_price')->label('Unit price')->numeric(),
                TextColumn::make('total')->numeric(),
            ])
            ->defaultSort('id')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
