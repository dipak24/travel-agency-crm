<?php

namespace App\Filament\Tenant\Resources\FixedDepartureResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\FixedDepartureResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFixedDeparture extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = FixedDepartureResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
