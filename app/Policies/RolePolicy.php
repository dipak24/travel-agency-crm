<?php

namespace App\Policies;

use App\Models\TenantUser;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(TenantUser $user, Role $role): bool
    {
        return $this->sameTenant($user, $role);
    }

    public function create(TenantUser $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(TenantUser $user, Role $role): Response
    {
        return $this->sameTenant($user, $role) && $this->isOwner($user)
            ? Response::allow()
            : Response::deny('Only a tenant owner can manage staff roles in this tenant.');
    }

    public function delete(TenantUser $user, Role $role): bool
    {
        return $this->sameTenant($user, $role) && $this->isOwner($user);
    }

    private function sameTenant(TenantUser $user, Role $role): bool
    {
        return $role->guard_name === 'tenant' && $role->team_id === $user->tenant_id;
    }

    private function isOwner(TenantUser $user): bool
    {
        return $user->hasRole('Tenant Owner');
    }
}
