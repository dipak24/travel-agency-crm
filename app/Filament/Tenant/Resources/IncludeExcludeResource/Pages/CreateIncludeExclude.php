<?php

namespace App\Filament\Tenant\Resources\IncludeExcludeResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\IncludeExcludeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIncludeExclude extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = IncludeExcludeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
