<?php
namespace App\Filament\Tenant\Resources\FixedDepartureResource\Pages;
use App\Filament\Tenant\Resources\FixedDepartureResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

class ListFixedDepartures extends ListRecords
{
    protected static string $resource = FixedDepartureResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
