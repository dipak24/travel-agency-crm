<?php

namespace App\Filament\Resources\SaasLeadResource\Pages;

use App\Filament\Resources\SaasLeadResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSaasLead extends EditRecord
{
    protected static string $resource = SaasLeadResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
