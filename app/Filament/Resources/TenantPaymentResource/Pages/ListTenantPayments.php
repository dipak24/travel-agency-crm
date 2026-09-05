<?php

namespace App\Filament\Resources\TenantPaymentResource\Pages;

use App\Filament\Resources\TenantPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantPayments extends ListRecords
{
    protected static string $resource = TenantPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
