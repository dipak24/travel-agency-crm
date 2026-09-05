<?php

namespace App\Filament\Tenant\Resources\IncludeExcludeResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\IncludeExcludeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIncludeExclude extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = IncludeExcludeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
