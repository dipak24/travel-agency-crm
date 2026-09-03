<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\TenantUser;
use Illuminate\Auth\Access\Response;

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
        return $this->canManage($user);
    }

    public function update(TenantUser $user, Customer $customer): Response
    {
        return $this->sameTenant($user, $customer)
            ? Response::allow()
            : Response::deny('Customer belongs to another tenant.');
    }

    public function delete(TenantUser $user, Customer $customer): bool
    {
        return $this->sameTenant($user, $customer) && $this->isOwner($user);
    }

    private function canManage(TenantUser $user): bool
    {
        return $this->isOwner($user) || $user->hasRole('Sales Agent');
    }

    private function sameTenant(TenantUser $user, Customer $customer): bool
    {
        return $user->tenant_id === $customer->tenant_id && $this->canManage($user);
    }

    private function isOwner(TenantUser $user): bool
    {
        return $user->hasRole('Tenant Owner');
    }
}
