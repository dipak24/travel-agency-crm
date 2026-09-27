<?php

namespace App\Filament\Tenant\Resources\PackageResource\RelationManagers;

use App\Filament\Forms\Components\MoneyInput;
use App\Models\PackageService;
use App\Models\Service;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Add-on services offered with this package (e.g. skydiving in Pokhara on an Everest trek). They
 * are listed first when CST or the customer add an add-on to a booking of this package. The same
 * services can still be sold on their own.
 */
class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceLinks';

    protected static ?string $title = 'Add-on services';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')
                ->label('Service')
                ->options(fn (): array => Service::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('package_id', $this->getOwnerRecord()->getKey())),
            MoneyInput::make('price_override')
                ->label('Price with this package')
                ->minValue(0)
                ->helperText('Leave empty to use the service\'s own price.'),
            Toggle::make('is_featured')->label('Featured')->inline(false),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('service'))
            ->columns([
                TextColumn::make('service.name')->label('Service'),
                TextColumn::make('price')->label('Price')
                    ->state(fn (PackageService $record): int => $record->price_override ?? $record->service?->price ?? 0)
                    ->money(fn (PackageService $record): string => $record->service?->currency ?? 'USD', divideBy: 100)
                    ->description(fn (PackageService $record): ?string => $record->service?->pricing_unit?->getLabel()),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add service')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
