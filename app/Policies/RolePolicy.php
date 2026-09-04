<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'view roles');
    }

    public function view(TenantUser $user, Role $role): bool
    {
        return $this->sameTenant($user, $role);
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create roles');
    }

    public function update(TenantUser $user, Role $role): Response
    {
        return $this->sameTenant($user, $role) && $this->hasPermission($user, 'update roles')
            ? Response::allow()
            : Response::deny('Only a tenant owner can manage staff roles in this tenant.');
    }

    public function delete(TenantUser $user, Role $role): bool
    {
        return $this->sameTenant($user, $role) && $this->hasPermission($user, 'delete roles');
    }

    private function sameTenant(TenantUser $user, Role $role): bool
    {
        return $role->guard_name === 'tenant' && $role->team_id === $user->tenant_id;
    }

    private function hasPermission(TenantUser $user, string $permission): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo($permission);
    }
}
