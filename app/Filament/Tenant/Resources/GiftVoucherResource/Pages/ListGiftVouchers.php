<?php

namespace App\Filament\Tenant\Resources\GiftVoucherResource\Pages;

use App\Filament\Tenant\Resources\GiftVoucherResource;
use Filament\Resources\Pages\ListRecords;

class ListGiftVouchers extends ListRecords
{
    protected static string $resource = GiftVoucherResource::class;

    /**
     * No create button — gift vouchers are bought by the customer, never manually issued by
     * staff. See GiftVoucherResource's class doc.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
