<?php
namespace App\Filament\Tenant\Resources\IncludeExcludeResource\Pages;
use App\Filament\Tenant\Resources\IncludeExcludeResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditIncludeExclude extends EditRecord
{
    protected static string $resource = IncludeExcludeResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
