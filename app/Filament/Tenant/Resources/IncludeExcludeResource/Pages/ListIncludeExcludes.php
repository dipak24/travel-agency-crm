<?php
namespace App\Filament\Tenant\Resources\IncludeExcludeResource\Pages;
use App\Filament\Tenant\Resources\IncludeExcludeResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

class ListIncludeExcludes extends ListRecords
{
    protected static string $resource = IncludeExcludeResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
