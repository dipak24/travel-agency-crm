<?php

namespace App\Filament\Resources\TenantInvoiceResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\TenantInvoiceResource;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Support\TenantContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenantInvoice extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = TenantInvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Tenant::query()->findOrFail($data['tenant_id']);
        $items = $data['items'] ?? [];
        unset($data['items']);

        $tenantContext = app(TenantContext::class);
        $tenantContext->set($tenant);

        try {
            /** @var TenantInvoice $invoice */
            $invoice = TenantInvoice::query()->create($data);

            foreach ($items as $item) {
                $invoice->items()->create($item);
            }

            $invoice->refreshTotals();

            return $invoice;
        } finally {
            $tenantContext->clear();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
