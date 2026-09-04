<?php

namespace App\Filament\Tenant\Resources\StaffResource\Pages;

use App\Filament\Tenant\Resources\StaffResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
