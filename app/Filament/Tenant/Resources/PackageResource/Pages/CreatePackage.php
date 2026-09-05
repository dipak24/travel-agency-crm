<?php

namespace App\Filament\Tenant\Resources\PackageResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\PackageResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePackage extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = PackageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
