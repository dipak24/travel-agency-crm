<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\SuperAdmin;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The audit log is read-only for everyone — entries are evidence, so nobody (Super Admin
 * included) can create, edit, or delete them through the app; old ones are only pruned by
 * Activity's retention rule. Each portal sees only its own entries: platform admins see
 * platform-level entries (no tenant_id) with `manage platform`, never a tenant's; tenant staff
 * see only their own tenant's entries with `view audit log`.
 */
class ActivityPolicy
{
    public function viewAny(SuperAdmin|TenantUser $user): bool
    {
        return $user instanceof SuperAdmin ? $this->platformCan($user) : $this->tenantCan($user);
    }

    public function view(SuperAdmin|TenantUser $user, Activity $activity): bool
    {
        if ($user instanceof SuperAdmin) {
            return $activity->tenant_id === null && $this->platformCan($user);
        }

        return $activity->tenant_id === $user->tenant_id && $this->tenantCan($user);
    }

    public function create(SuperAdmin|TenantUser $user): bool
    {
        return false;
    }

    public function update(SuperAdmin|TenantUser $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(SuperAdmin|TenantUser $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(SuperAdmin|TenantUser $user): bool
    {
        return false;
    }

    private function platformCan(SuperAdmin $user): bool
    {
        // Super admins use the fixed sentinel team id (0) — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        if (! Permission::query()->where('name', 'manage platform')->where('guard_name', 'super_admin')->exists()) {
            return $user->hasRole('Super Admin');
        }

        return $user->hasPermissionTo('manage platform');
    }

    private function tenantCan(TenantUser $user): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'view audit log')->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo('view audit log');
    }
}
