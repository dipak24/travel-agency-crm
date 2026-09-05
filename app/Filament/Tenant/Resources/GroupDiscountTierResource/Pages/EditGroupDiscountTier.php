<?php

namespace App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\GroupDiscountTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGroupDiscountTier extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = GroupDiscountTierResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
