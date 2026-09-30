<?php

namespace App\Filament\Tenant\Resources;

use App\Enums\PricingUnit;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\IncludeExcludeResource\Pages;
use App\Models\IncludeExclude;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The agency's catalog of inclusion/exclusion items, each with a unit price (e.g. "Hotel in
 * Kathmandu — 45 per person per day"). Packages pick from this list; bookings snapshot it.
 */
class IncludeExcludeResource extends Resource
{
    protected static ?string $model = IncludeExclude::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Usually')
                ->options(['include' => 'Included', 'exclude' => 'Excluded'])
                ->helperText('A default only — each package decides whether the item is in its price.')
                ->required(),
            TextInput::make('title')->required()->maxLength(255),
            MoneyInput::make('unit_price')->label('Unit price')->minValue(0)->default(0)->required(),
            Select::make('pricing_unit')
                ->label('Priced per')
                ->options(PricingUnit::class)
                ->default(PricingUnit::PerPerson->value)
                ->required(),
            Textarea::make('description')->rows(4)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->integer()->minValue(0)->default(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('unit_price')->label('Unit price')
                    ->money(fn (): string => auth('tenant')->user()->tenant->currency ?? 'USD', divideBy: 100)
                    ->description(fn (IncludeExclude $record): ?string => $record->pricing_unit?->getLabel()),
                TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                ActionGroup::make([EditAction::make(), DeleteAction::make()])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncludeExcludes::route('/'),
            'create' => Pages\CreateIncludeExclude::route('/create'),
            'edit' => Pages\EditIncludeExclude::route('/{record}/edit'),
        ];
    }
}
