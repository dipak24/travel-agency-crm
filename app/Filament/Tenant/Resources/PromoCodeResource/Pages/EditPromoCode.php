<?php

namespace App\Filament\Tenant\Resources\PromoCodeResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\PromoCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromoCode extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = PromoCodeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
