<?php

namespace App\Filament\Resources\TenantInvoiceResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\TenantInvoiceResource;
use App\Models\TenantInvoice;
use App\Support\TenantContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTenantInvoice extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = TenantInvoiceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var TenantInvoice $invoice */
        $invoice = $this->getRecord();

        $data['items'] = $invoice->items()->withoutGlobalScopes()
            ->get(['type', 'description', 'qty', 'unit_price'])
            ->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantInvoice $record */
        $items = $data['items'] ?? [];
        unset($data['items']);

        $tenantContext = app(TenantContext::class);
        $tenantContext->set($record->tenant()->withoutGlobalScopes()->firstOrFail());

        try {
            $record->update($data);

            $record->items()->withoutGlobalScopes()->delete();

            foreach ($items as $item) {
                $record->items()->create($item);
            }

            $record->refreshTotals();

            return $record;
        } finally {
            $tenantContext->clear();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
