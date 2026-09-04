<?php
namespace App\Filament\Tenant\Resources\ServiceResource\Pages;
use App\Filament\Tenant\Resources\ServiceResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
