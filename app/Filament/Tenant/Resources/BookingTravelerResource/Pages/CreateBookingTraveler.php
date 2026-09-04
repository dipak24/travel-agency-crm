<?php

namespace App\Filament\Tenant\Resources\BookingTravelerResource\Pages;

use App\Filament\Tenant\Resources\BookingTravelerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBookingTraveler extends CreateRecord
{
    protected static string $resource = BookingTravelerResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
