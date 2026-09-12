<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\Booking;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class UpcomingTrips extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Upcoming trips')
            ->query(
                Booking::query()
                    ->whereNotNull('start_date')
                    ->where('start_date', '>=', now()->startOfDay())
                    ->whereNotIn('status', ['cancelled'])
                    ->orderBy('start_date')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('trip_name')->label('Trip')->searchable(),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('pax_count')->label('Pax'),
                TextColumn::make('status')->badge(),
            ])
            ->paginated(false);
    }
}
