<?php

namespace App\Filament\Resources\SaasLeadResource\Pages;

use App\Filament\Resources\SaasLeadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSaasLeads extends ListRecords
{
    protected static string $resource = SaasLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
