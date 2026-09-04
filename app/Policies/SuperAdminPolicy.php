<?php

namespace App\Policies;

use App\Models\SuperAdmin;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return $this->hasPermission($user);
    }

    public function view(SuperAdmin $user, SuperAdmin $record): bool
    {
        return $this->hasPermission($user);
    }

    public function create(SuperAdmin $user): bool
    {
        return $this->hasPermission($user);
    }

    public function update(SuperAdmin $user, SuperAdmin $record): bool
    {
        return $this->hasPermission($user);
    }

    public function delete(SuperAdmin $user, SuperAdmin $record): Response
    {
        return $this->hasPermission($user) && $user->id !== $record->id
            ? Response::allow()
            : Response::deny('You cannot delete your own platform account.');
    }

    private function hasPermission(SuperAdmin $user): bool
    {
        // Super admins aren't tenant-scoped, so they use the fixed sentinel
        // team id (0) instead of a real tenant id — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        if (! Permission::query()->where('name', 'manage platform')->where('guard_name', 'super_admin')->exists()) {
            return $user->hasRole('Super Admin');
        }

        return $user->hasPermissionTo('manage platform');
    }
}
