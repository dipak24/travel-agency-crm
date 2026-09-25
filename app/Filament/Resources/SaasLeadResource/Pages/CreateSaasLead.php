<?php

namespace App\Filament\Resources\SaasLeadResource\Pages;

use App\Filament\Resources\SaasLeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSaasLead extends CreateRecord
{
    protected static string $resource = SaasLeadResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
