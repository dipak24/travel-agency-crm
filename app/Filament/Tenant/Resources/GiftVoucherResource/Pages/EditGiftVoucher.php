<?php

namespace App\Filament\Tenant\Resources\GiftVoucherResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\GiftVoucherResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGiftVoucher extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = GiftVoucherResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
