<?php

namespace App\Filament\Tenant\Resources\GiftVoucherResource\Pages;

use App\Filament\Tenant\Resources\GiftVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGiftVouchers extends ListRecords
{
    protected static string $resource = GiftVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
