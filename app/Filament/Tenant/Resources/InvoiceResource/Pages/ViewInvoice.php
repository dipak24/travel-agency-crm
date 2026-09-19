<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    use HasFullWidthForm;

    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        /** @var Invoice $record */
        $record = $this->getRecord();

        return $record->customer?->name ?? $record->invoice_no;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...InvoiceResource::rowActions(),
            EditAction::make(),
        ];
    }
}
