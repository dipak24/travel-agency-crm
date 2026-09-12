<?php

namespace App\Filament\Concerns;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared `canView()`/`canAccess()` gate for admin-panel dashboard widgets and pages: falls back to
 * the seeded `Super Admin` role when the given platform permission hasn't been split out onto its
 * own role yet, same pattern as TenantPolicy/App\Filament\Pages\MailSettings.
 */
trait ChecksPlatformPermission
{
    private static function platformUserCan(string $permission): bool
    {
        $user = auth('super_admin')->user();

        if (! $user) {
            return false;
        }

        // Super admins aren't tenant-scoped, so they use the fixed sentinel
        // team id (0) instead of a real tenant id — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'super_admin')->exists()) {
            return $user->hasRole('Super Admin');
        }

        return $user->hasPermissionTo($permission);
    }
}
