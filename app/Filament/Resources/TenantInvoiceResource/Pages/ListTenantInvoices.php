<?php

namespace App\Filament\Resources\TenantInvoiceResource\Pages;

use App\Filament\Resources\TenantInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantInvoices extends ListRecords
{
    protected static string $resource = TenantInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
