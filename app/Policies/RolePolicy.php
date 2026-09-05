<?php

namespace App\Policies;

use App\Models\SuperAdmin;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePolicy
{
    public function viewAny(TenantUser|SuperAdmin $user): bool
    {
        return $this->hasPermission($user, 'view roles');
    }

    public function view(TenantUser|SuperAdmin $user, Role $role): bool
    {
        return $this->sameScope($user, $role);
    }

    public function create(TenantUser|SuperAdmin $user): bool
    {
        return $this->hasPermission($user, 'create roles');
    }

    public function update(TenantUser|SuperAdmin $user, Role $role): Response
    {
        return $this->sameScope($user, $role) && $this->hasPermission($user, 'update roles')
            ? Response::allow()
            : Response::deny($user instanceof SuperAdmin
                ? 'Only a platform admin can manage platform roles.'
                : 'Only a tenant owner can manage staff roles in this tenant.');
    }

    public function delete(TenantUser|SuperAdmin $user, Role $role): bool
    {
        return $this->sameScope($user, $role) && $this->hasPermission($user, 'delete roles');
    }

    private function sameScope(TenantUser|SuperAdmin $user, Role $role): bool
    {
        if ($user instanceof SuperAdmin) {
            return $role->guard_name === 'super_admin' && $role->team_id === 0;
        }

        return $role->guard_name === 'tenant' && $role->team_id === $user->tenant_id;
    }

    private function hasPermission(TenantUser|SuperAdmin $user, string $permission): bool
    {
        if ($user instanceof SuperAdmin) {
            // Super admins aren't tenant-scoped, so they use the fixed sentinel
            // team id (0) instead of a real tenant id — see ResolvePlatformTeam.
            app(PermissionRegistrar::class)->setPermissionsTeamId(0);

            if (! Permission::query()->where('name', $permission)->where('guard_name', 'super_admin')->exists()) {
                return $user->hasRole('Super Admin');
            }

            return $user->hasPermissionTo($permission);
        }

        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo($permission);
    }
}
