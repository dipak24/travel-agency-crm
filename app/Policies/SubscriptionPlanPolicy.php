<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SubscriptionPlanPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return $this->hasPermission($user);
    }

    public function view(SuperAdmin $user, SubscriptionPlan $plan): bool
    {
        return $this->hasPermission($user);
    }

    public function create(SuperAdmin $user): bool
    {
        return $this->hasPermission($user);
    }

    public function update(SuperAdmin $user, SubscriptionPlan $plan): bool
    {
        return $this->hasPermission($user);
    }

    public function delete(SuperAdmin $user, SubscriptionPlan $plan): bool
    {
        return $this->hasPermission($user);
    }

    private function hasPermission(SuperAdmin $user): bool
    {
        // Super admins aren't tenant-scoped, so they use the fixed sentinel
        // team id (0) instead of a real tenant id — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        if (! Permission::query()->where('name', 'manage billing')->where('guard_name', 'super_admin')->exists()) {
            return $user->hasRole('Super Admin');
        }

        return $user->hasPermissionTo('manage billing');
    }
}
