<?php

namespace App\Filament\Tenant\Resources\PackageResource\RelationManagers;

use App\Filament\Tenant\Resources\FixedDepartureResource;
use App\Models\FixedDeparture;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FixedDeparturesRelationManager extends RelationManager
{
    protected static string $relationship = 'fixedDepartures';

    protected static ?string $title = 'Fixed departures';

    public function form(Schema $schema): Schema
    {
        return $schema->components(FixedDepartureResource::departureFields(fn (): ?int => $this->getOwnerRecord()->duration_days))->columns(2);
    }

    public function table(Table $table): Table
    {
        $currency = fn (): string => auth('tenant')->user()->tenant->currency ?? 'USD';

        return $table
            ->columns([
                TextColumn::make('start_date')->date('j M Y')->sortable(),
                TextColumn::make('end_date')->date('j M Y'),
                TextColumn::make('booked_slots')->label('Booked')
                    ->formatStateUsing(fn (FixedDeparture $record): string => "{$record->booked_slots} / {$record->total_slots}"),
                TextColumn::make('remaining')->label('Seats left')->state(fn (FixedDeparture $record): int => $record->remainingSlots()),
                TextColumn::make('price')->label('Price per person')
                    ->state(fn (FixedDeparture $record): int => $record->perPersonPrice())
                    ->money($currency, divideBy: 100),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('start_date')
            ->headerActions([CreateAction::make()->label('Add departure')])
            ->recordActions([EditAction::make()]);
    }
}
