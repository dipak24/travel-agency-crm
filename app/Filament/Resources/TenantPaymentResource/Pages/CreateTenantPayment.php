<?php

namespace App\Filament\Resources\TenantPaymentResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\TenantPaymentResource;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Support\TenantContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenantPayment extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = TenantPaymentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $invoice = TenantInvoice::query()->withoutGlobalScopes()->findOrFail($data['tenant_invoice_id']);

        $tenantContext = app(TenantContext::class);
        $tenantContext->set($invoice->tenant()->withoutGlobalScopes()->firstOrFail());

        try {
            return TenantPayment::query()->create($data);
        } finally {
            $tenantContext->clear();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
