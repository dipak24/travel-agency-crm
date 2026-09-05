<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\InvoiceResource;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = InvoiceResource::class;
}
