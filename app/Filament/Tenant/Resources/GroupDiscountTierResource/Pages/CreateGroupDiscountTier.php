<?php
namespace App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages;
use App\Filament\Tenant\Resources\GroupDiscountTierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGroupDiscountTier extends CreateRecord
{
    protected static string $resource = GroupDiscountTierResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
