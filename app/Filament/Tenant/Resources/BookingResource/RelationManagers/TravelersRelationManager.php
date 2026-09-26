<?php

namespace App\Filament\Tenant\Resources\BookingResource\RelationManagers;

use App\Filament\Tenant\Resources\BookingTravelerResource;
use App\Models\BookingTraveler;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TravelersRelationManager extends RelationManager
{
    protected static string $relationship = 'travelers';

    public function form(Schema $schema): Schema
    {
        return $schema->components(BookingTravelerResource::detailsSchema())->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Full name')->searchable()->sortable()
                    ->description(fn (BookingTraveler $record): string => collect([$record->email, $record->phone])->filter()->implode(' · ')),
                TextColumn::make('nationality.nationality')->label('Nationality')->placeholder('—'),
                TextColumn::make('dob')->date(),
                TextColumn::make('documents_count')->label('Documents')->counts('documents'),
                TextColumn::make('document_status')->badge(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
