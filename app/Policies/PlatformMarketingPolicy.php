<?php

namespace App\Policies;

use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * SaaS leads — gated by the `manage marketing` platform permission.
 */
class PlatformMarketingPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return $this->hasPermission($user);
    }

    public function view(SuperAdmin $user, Model $record): bool
    {
        return $this->hasPermission($user);
    }

    public function create(SuperAdmin $user): bool
    {
        return $this->hasPermission($user);
    }

    public function update(SuperAdmin $user, Model $record): bool
    {
        return $this->hasPermission($user);
    }

    public function delete(SuperAdmin $user, Model $record): bool
    {
        return $this->hasPermission($user);
    }

    private function hasPermission(SuperAdmin $user): bool
    {
        // Super admins use the fixed sentinel team id (0) — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        if (! Permission::query()->where('name', 'manage marketing')->where('guard_name', 'super_admin')->exists()) {
            return $user->hasRole('Super Admin');
        }

        return $user->hasPermissionTo('manage marketing');
    }
}
