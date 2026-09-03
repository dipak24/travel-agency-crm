<?php
namespace App\Filament\Tenant\Resources\ServiceAvailabilityResource\Pages;
use App\Filament\Tenant\Resources\ServiceAvailabilityResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

class ListServiceAvailabilities extends ListRecords
{
    protected static string $resource = ServiceAvailabilityResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
