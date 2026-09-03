<?php
namespace App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages;
use App\Filament\Tenant\Resources\GroupDiscountTierResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

class ListGroupDiscountTiers extends ListRecords
{
    protected static string $resource = GroupDiscountTierResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
