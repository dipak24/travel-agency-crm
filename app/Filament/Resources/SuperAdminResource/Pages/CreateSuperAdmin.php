<?php

namespace App\Filament\Resources\SuperAdminResource\Pages;

use App\Filament\Resources\SuperAdminResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSuperAdmin extends CreateRecord
{
    protected static string $resource = SuperAdminResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
