<?php

namespace App\Filament\Tenant\Resources\ServiceAvailabilityResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\ServiceAvailabilityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceAvailability extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = ServiceAvailabilityResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
