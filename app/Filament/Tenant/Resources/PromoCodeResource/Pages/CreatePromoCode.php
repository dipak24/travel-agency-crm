<?php

namespace App\Filament\Tenant\Resources\PromoCodeResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\PromoCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromoCode extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = PromoCodeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
