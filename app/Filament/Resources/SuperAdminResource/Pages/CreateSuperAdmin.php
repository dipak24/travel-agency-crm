<?php

namespace App\Filament\Resources\SuperAdminResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\SuperAdminResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSuperAdmin extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = SuperAdminResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
