<?php

namespace App\Filament\Tenant\Resources\StaffResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\StaffResource;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = StaffResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
