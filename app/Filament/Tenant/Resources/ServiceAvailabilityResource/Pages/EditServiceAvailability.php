<?php
namespace App\Filament\Tenant\Resources\ServiceAvailabilityResource\Pages;
use App\Filament\Tenant\Resources\ServiceAvailabilityResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditServiceAvailability extends EditRecord
{
    protected static string $resource = ServiceAvailabilityResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
