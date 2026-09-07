<?php

namespace App\Filament\Tenant\Resources\BookingResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TravelersRelationManager extends RelationManager
{
    protected static string $relationship = 'travelers';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('passport_no')->label('Passport number')->maxLength(255),
            DatePicker::make('dob')->label('Date of birth')->native(false),
            Select::make('document_status')->options([
                'pending' => 'Pending',
                'received' => 'Received',
                'verified' => 'Verified',
            ])->default('pending')->required(),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('dob')->date(),
                TextColumn::make('document_status')->badge(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
