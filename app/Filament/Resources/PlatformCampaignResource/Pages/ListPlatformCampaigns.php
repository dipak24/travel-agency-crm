<?php

namespace App\Filament\Resources\PlatformCampaignResource\Pages;

use App\Filament\Resources\PlatformCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformCampaigns extends ListRecords
{
    protected static string $resource = PlatformCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
