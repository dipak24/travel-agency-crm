<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class InvoicePolicy
{
    /**
     * Also used by the `customer` guard for the portal's read-only invoice
     * list/detail pages — see BookingPolicy for the same pattern.
     */
    public function viewAny(TenantUser|Customer $user): bool
    {
        return $user instanceof Customer || $this->canManage($user);
    }

    public function view(TenantUser|Customer $user, Invoice $invoice): bool
    {
        if ($user instanceof Customer) {
            return $user->tenant_id === $invoice->tenant_id && $user->id === $invoice->customer_id;
        }

        return $this->sameTenant($user, $invoice);
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create invoices');
    }

    public function update(TenantUser $user, Invoice $invoice): Response
    {
        return $this->sameTenant($user, $invoice, 'update invoices')
            ? Response::allow()
            : Response::deny('Invoice belongs to another tenant.');
    }

    public function delete(TenantUser $user, Invoice $invoice): bool
    {
        return $this->sameTenant($user, $invoice, 'delete invoices');
    }

    private function canManage(TenantUser $user): bool
    {
        $this->setTenantContext($user);

        return $user->hasPermissionTo('view invoices');
    }

    private function sameTenant(TenantUser $user, Invoice $invoice, string $permission = 'view invoices'): bool
    {
        return $user->tenant_id === $invoice->tenant_id && $this->hasPermission($user, $permission);
    }

    private function hasPermission(TenantUser $user, string $permission): bool
    {
        $this->setTenantContext($user);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'tenant')->exists()) {
            return $user->hasAnyRole(['Tenant Owner', 'Sales Agent', 'Operations', 'Accountant']);
        }

        return $user->hasPermissionTo($permission);
    }

    private function setTenantContext(TenantUser $user): void
    {
        app(TenantContext::class)->set($user->tenant);
    }
}
