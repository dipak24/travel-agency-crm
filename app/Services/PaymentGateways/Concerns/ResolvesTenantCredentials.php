<?php

namespace App\Services\PaymentGateways\Concerns;

use App\Models\Invoice;
use App\Models\TenantPaymentGateway;

trait ResolvesTenantCredentials
{
    /**
     * Look up this gateway's settings for the invoice's own tenant, bypassing whatever
     * TenantContext (if any) happens to be set on the current request — a gateway call is always
     * about one specific invoice's specific tenant, not "whoever is currently logged in".
     */
    private function settingsFor(Invoice $invoice): ?TenantPaymentGateway
    {
        return TenantPaymentGateway::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('gateway', $this->key())
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialsFor(Invoice $invoice): array
    {
        return $this->settingsFor($invoice)?->credentials ?? [];
    }
}
