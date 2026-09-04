<?php

namespace App\Filament\Resources\SuperAdminResource\Pages;

use App\Filament\Resources\SuperAdminResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuperAdmins extends ListRecords
{
    protected static string $resource = SuperAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
