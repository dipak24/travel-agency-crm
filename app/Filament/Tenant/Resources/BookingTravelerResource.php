<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\CountrySelect;
use App\Filament\Tenant\Resources\BookingTravelerResource\Pages;
use App\Models\BookingTraveler;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
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
            ...static::detailsSchema(),
        ]);
    }

    /**
     * A traveller's own details, shared by this resource and the booking's TravelersRelationManager.
     * Staff may leave email/nationality blank (e.g. an infant added at the desk); the customer
     * portal requires them.
     *
     * @return array<int, Component>
     */
    public static function detailsSchema(): array
    {
        return [
            TextInput::make('name')->label('Full name')->required()->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(255),
            DatePicker::make('dob')->label('Date of birth')->native(false)->maxDate(today()),
            CountrySelect::make('nationality_id', nationality: true)->label('Nationality'),
            TextInput::make('passport_no')->label('Passport number')->maxLength(255),
            Textarea::make('address')->rows(2),
            Select::make('document_status')->options([
                'pending' => 'Pending',
                'received' => 'Received',
                'verified' => 'Verified',
            ])->default('pending')->required(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Full name')->searchable()->sortable()
                ->description(fn (BookingTraveler $record): string => collect([$record->email, $record->phone])->filter()->implode(' · ')),
            TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('booking.trip_name')->label('Booking')->searchable(),
            TextColumn::make('nationality.nationality')->label('Nationality')->placeholder('—'),
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
