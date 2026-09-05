<?php

namespace App\Filament\Tenant\Resources\ServiceAvailabilityResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\ServiceAvailabilityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceAvailability extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = ServiceAvailabilityResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
