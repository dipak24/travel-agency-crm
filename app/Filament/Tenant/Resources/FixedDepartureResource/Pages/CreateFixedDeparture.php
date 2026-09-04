<?php
namespace App\Filament\Tenant\Resources\FixedDepartureResource\Pages;
use App\Filament\Tenant\Resources\FixedDepartureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFixedDeparture extends CreateRecord
{
    protected static string $resource = FixedDepartureResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
