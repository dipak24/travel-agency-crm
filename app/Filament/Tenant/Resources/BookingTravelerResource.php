<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\BookingTravelerResource\Pages;
use App\Models\BookingTraveler;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BookingTravelerResource extends Resource
{
    protected static ?string $model = BookingTraveler::class;

    protected static ?string $navigationLabel = 'Travelers';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('booking_id')
                ->label('Booking')
                ->relationship(
                    name: 'booking',
                    titleAttribute: 'trip_name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->latest(),
                )
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('passport_no')->label('Passport number')->maxLength(255),
            DatePicker::make('dob')->label('Date of birth')->native(false),
            Select::make('document_status')->options([
                'pending' => 'Pending',
                'received' => 'Received',
                'verified' => 'Verified',
            ])->default('pending')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('booking.trip_name')->label('Booking')->searchable(),
            TextColumn::make('dob')->date(),
            TextColumn::make('document_status')->badge(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingTravelers::route('/'),
            'create' => Pages\CreateBookingTraveler::route('/create'),
            'edit' => Pages\EditBookingTraveler::route('/{record}/edit'),
        ];
    }
}
