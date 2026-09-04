<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\BookingResource\Pages;
use App\Models\Booking;
use Filament\Forms\Components\TextInput;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationLabel = 'Bookings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload()->required(),
            TextInput::make('trip_name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            TextInput::make('pax_count')->numeric()->integer()->minValue(1)->required()->default(1),
            TextInput::make('total_amount')->label('Total (minor units)')->numeric()->integer()->minValue(0)->required()->default(0),
            Select::make('status')->options([
                'pending' => 'Pending',
                'confirmed' => 'Confirmed',
                'ongoing' => 'Ongoing',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
            ])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('trip_name')->searchable()->sortable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('fixedDeparture.start_date')->label('Departure')->date()->sortable(),
            TextColumn::make('pax_count')->label('Pax'),
            TextColumn::make('total_amount')->label('Total (minor units)')->numeric(),
            TextColumn::make('status')->badge()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
