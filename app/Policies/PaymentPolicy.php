<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class PaymentPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment);
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create invoices');
    }

    public function update(TenantUser $user, Payment $payment): Response
    {
        return $this->sameTenant($user, $payment, 'update invoices')
            ? Response::allow()
            : Response::deny('Payment belongs to another tenant.');
    }

    public function delete(TenantUser $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment, 'delete invoices');
    }

    public function refund(TenantUser $user, Payment $payment): bool
    {
        return $this->sameTenant($user, $payment, 'update invoices');
    }

    private function canManage(TenantUser $user): bool
    {
        $this->setTenantContext($user);

        return $user->hasPermissionTo('view invoices');
    }

    private function sameTenant(TenantUser $user, Payment $payment, string $permission = 'view invoices'): bool
    {
        return $user->tenant_id === $payment->tenant_id && $this->hasPermission($user, $permission);
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
