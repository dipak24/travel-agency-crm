<?php
namespace App\Filament\Tenant\Resources\FixedDepartureResource\Pages;
use App\Filament\Tenant\Resources\FixedDepartureResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditFixedDeparture extends EditRecord
{
    protected static string $resource = FixedDepartureResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
