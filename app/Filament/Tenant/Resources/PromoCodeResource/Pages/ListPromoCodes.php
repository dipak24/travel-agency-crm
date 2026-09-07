<?php

namespace App\Filament\Tenant\Resources\PromoCodeResource\Pages;

use App\Filament\Tenant\Resources\PromoCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromoCodes extends ListRecords
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
