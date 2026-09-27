<?php

namespace App\Filament\Tenant\Resources;

use App\Enums\PricingUnit;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\ServiceResource\Pages;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('category')->maxLength(100)->placeholder('e.g. Activity, Transport, Flight'),
            Textarea::make('description')->rows(4)->columnSpanFull(),
            MoneyInput::make('price')->label('Price')->minValue(0)->required(),
            Select::make('pricing_unit')->label('Priced per')->options(PricingUnit::class)->default(PricingUnit::PerTrip->value)->required(),
            TextInput::make('currency')->default('USD')->required()->length(3),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable()->description(fn (Service $record): ?string => $record->category), TextColumn::make('pricing_unit')->label('Per')->badge(), TextColumn::make('price')->money(fn (Service $record): string => $record->currency, divideBy: 100), TextColumn::make('currency'), IconColumn::make('is_active')->boolean()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServices::route('/'), 'create' => Pages\CreateService::route('/create'), 'edit' => Pages\EditService::route('/{record}/edit')];
    }
}
