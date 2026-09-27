<?php

namespace App\Filament\Tenant\Resources\PackageResource\RelationManagers;

use App\Filament\Forms\Components\MoneyInput;
use App\Models\IncludeExclude;
use App\Models\PackageIncludeExclude;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * The package's inclusion/exclusion list. Items "included in the price" are covered by the base
 * price (a booking that drops one is refunded its cost); the rest are listed as exclusions that a
 * booking can add for a charge.
 */
class IncludeExcludeItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'includeExcludeItems';

    protected static ?string $title = 'Inclusions & exclusions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('include_exclude_id')
                ->label('Catalog item')
                ->options(fn (): array => IncludeExclude::query()->orderBy('sort_order')->pluck('title', 'id')->all())
                ->searchable()
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('package_id', $this->getOwnerRecord()->getKey())),
            Toggle::make('is_included')
                ->label('Included in the package price')
                ->helperText('Off: listed as an exclusion that a booking can add for a charge.')
                ->default(true)
                ->inline(false),
            MoneyInput::make('unit_price_override')
                ->label('Price on this package')
                ->minValue(0)
                ->helperText('Leave empty to use the catalog price.'),
            TextInput::make('sort_order')->numeric()->integer()->minValue(0)->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        $currency = fn (): string => auth('tenant')->user()->tenant->currency ?? 'USD';

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('includeExclude'))
            ->columns([
                TextColumn::make('includeExclude.title')->label('Item'),
                IconColumn::make('is_included')->label('In price')->boolean(),
                TextColumn::make('unit_price')->label('Price')
                    ->state(fn (PackageIncludeExclude $record): int => $record->unitPrice())
                    ->money($currency, divideBy: 100)
                    ->description(fn (PackageIncludeExclude $record): ?string => $record->includeExclude?->pricing_unit?->getLabel()),
                TextColumn::make('sort_order')->label('Order'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([CreateAction::make()->label('Add item')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
