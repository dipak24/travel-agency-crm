<?php

namespace App\Filament\Resources\PlatformCampaignResource\Pages;

use App\Filament\Resources\PlatformCampaignResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlatformCampaign extends EditRecord
{
    protected static string $resource = PlatformCampaignResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
