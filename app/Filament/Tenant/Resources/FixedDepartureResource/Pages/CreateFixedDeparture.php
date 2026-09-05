<?php

namespace App\Filament\Tenant\Resources\FixedDepartureResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\FixedDepartureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFixedDeparture extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = FixedDepartureResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
