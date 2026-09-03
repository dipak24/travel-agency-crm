<?php
namespace App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages;
use App\Filament\Tenant\Resources\GroupDiscountTierResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditGroupDiscountTier extends EditRecord
{
    protected static string $resource = GroupDiscountTierResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
