<?php
namespace App\Filament\Tenant\Resources\ServiceResource\Pages;
use App\Filament\Tenant\Resources\ServiceResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
