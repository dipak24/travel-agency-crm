<?php

namespace App\Filament\Tenant\Resources\LeadResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\LeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = LeadResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
