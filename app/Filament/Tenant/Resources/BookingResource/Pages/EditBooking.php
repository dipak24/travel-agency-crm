<?php

namespace App\Filament\Tenant\Resources\BookingResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\BookingResource;
use Filament\Resources\Pages\EditRecord;

class EditBooking extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = BookingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
