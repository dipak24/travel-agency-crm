<?php

namespace App\Filament\Tenant\Resources\BookingResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\BookingResource;
use App\Filament\Tenant\Resources\BookingResource\Concerns\SyncsBookingExtras;
use Filament\Resources\Pages\CreateRecord;

class CreateBooking extends CreateRecord
{
    use HasFullWidthForm, SyncsBookingExtras;

    protected static string $resource = BookingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $this->syncBookingExtras();
    }
}
