<?php

namespace App\Filament\Tenant\Resources\CustomerResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\CustomerResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
