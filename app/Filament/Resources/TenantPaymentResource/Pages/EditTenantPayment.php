<?php

namespace App\Filament\Resources\TenantPaymentResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\TenantPaymentResource;
use App\Models\TenantInvoice;
use App\Support\TenantContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTenantPayment extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = TenantPaymentResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $invoice = TenantInvoice::query()->withoutGlobalScopes()->findOrFail($data['tenant_invoice_id']);

        $tenantContext = app(TenantContext::class);
        $tenantContext->set($invoice->tenant()->withoutGlobalScopes()->firstOrFail());

        try {
            $record->update($data);

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
