<?php

namespace App\Filament\Tenant\Resources\GiftVoucherResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\GiftVoucherResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGiftVoucher extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = GiftVoucherResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
