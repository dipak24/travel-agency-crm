<?php

namespace App\Filament\Tenant\Resources\BookingTravelerResource\Pages;

use App\Filament\Tenant\Resources\BookingTravelerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookingTravelers extends ListRecords
{
    protected static string $resource = BookingTravelerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
