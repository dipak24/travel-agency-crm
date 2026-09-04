<?php

namespace App\Filament\Tenant\Resources\BookingTravelerResource\Pages;

use App\Filament\Tenant\Resources\BookingTravelerResource;
use Filament\Resources\Pages\EditRecord;

class EditBookingTraveler extends EditRecord
{
    protected static string $resource = BookingTravelerResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
