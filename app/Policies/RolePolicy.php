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
        if ($this->isProtected($role)) {
            return Response::deny('The tenant owner role is set by the platform admin and can\'t be changed from the tenant portal.');
        }

        return $this->sameScope($user, $role) && $this->hasPermission($user, 'update roles')
            ? Response::allow()
            : Response::deny($user instanceof SuperAdmin
                ? 'Only a platform admin can manage platform roles.'
                : 'Only a tenant owner can manage staff roles in this tenant.');
    }

    public function delete(TenantUser|SuperAdmin $user, Role $role): bool
    {
        if ($this->isProtected($role)) {
            return false;
        }

        return $this->sameScope($user, $role) && $this->hasPermission($user, 'delete roles');
    }

    private function sameScope(TenantUser|SuperAdmin $user, Role $role): bool
    {
        if ($user instanceof SuperAdmin) {
            return $role->guard_name === 'super_admin' && $role->team_id === 0;
        }

        return $role->guard_name === 'tenant' && $role->team_id === $user->tenant_id;
    }

    /**
     * The tenant owner role is the tenant portal's "super admin" — its own
     * permission set is provisioned once when the tenant is created and must
     * stay outside the reach of anyone logged into that tenant's portal,
     * including the owner themselves.
     */
    private function isProtected(Role $role): bool
    {
        return $role->guard_name === 'tenant' && $role->name === 'Tenant Owner';
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
