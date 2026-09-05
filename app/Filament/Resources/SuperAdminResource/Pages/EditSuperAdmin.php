<?php

namespace App\Filament\Resources\SuperAdminResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\SuperAdminResource;
use Filament\Resources\Pages\EditRecord;

class EditSuperAdmin extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = SuperAdminResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
