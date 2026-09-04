<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class CustomerPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer);
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create customers');
    }

    public function update(TenantUser $user, Customer $customer): Response
    {
        return $this->sameTenant($user, $customer, 'update customers')
            ? Response::allow()
            : Response::deny('Customer belongs to another tenant.');
    }

    public function delete(TenantUser $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer, 'delete customers');
    }

    private function canManage(TenantUser $user): bool
    {
        $this->setTenantContext($user);

        return $user->hasPermissionTo('view customers');
    }

    private function sameTenant(TenantUser $user, Customer $customer, string $permission = 'view customers'): bool
    {
        return $user->tenant_id === $customer->tenant_id && $this->hasPermission($user, $permission);
    }

    private function hasPermission(TenantUser $user, string $permission): bool
    {
        $this->setTenantContext($user);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'tenant')->exists()) {
            return $user->hasAnyRole(['Tenant Owner', 'Sales Agent']);
        }

        return $user->hasPermissionTo($permission);
    }

    private function setTenantContext(TenantUser $user): void
    {
        app(TenantContext::class)->set($user->tenant);
    }
}
