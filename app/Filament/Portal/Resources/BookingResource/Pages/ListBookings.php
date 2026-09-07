<?php

namespace App\Filament\Portal\Resources\BookingResource\Pages;

use App\Filament\Portal\Resources\BookingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    public function getTabs(): array
    {
        return [
            'current' => Tab::make('Current')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(
                    fn (Builder $query): Builder => $query->whereNull('end_date')->orWhere('end_date', '>=', today())
                )),
            'past' => Tab::make('Past')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('end_date', '<', today())),
        ];
    }
}
