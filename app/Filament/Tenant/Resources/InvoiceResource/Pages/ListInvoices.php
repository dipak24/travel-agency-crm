<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\Pages;

use App\Filament\Tenant\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
