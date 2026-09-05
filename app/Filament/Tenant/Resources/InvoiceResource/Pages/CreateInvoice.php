<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = InvoiceResource::class;
}
