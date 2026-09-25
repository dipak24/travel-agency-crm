<?php

namespace App\Policies;

use App\Models\PlatformEmailTemplate;
use App\Models\SuperAdmin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Platform email templates come in two kinds on one table: marketing templates (`manage
 * marketing`, full CRUD) and system email templates (`manage platform`, edit only — they're
 * seeded one per App\Support\SystemEmailTypes key and can never be created or deleted by hand).
 */
class PlatformEmailTemplatePolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return $this->hasPermission($user, 'manage marketing') || $this->hasPermission($user, 'manage platform');
    }

    public function view(SuperAdmin $user, PlatformEmailTemplate $template): bool
    {
        return $this->hasPermission($user, $this->permissionFor($template));
    }

    public function create(SuperAdmin $user): bool
    {
        return $this->hasPermission($user, 'manage marketing');
    }

    /**
     * A marketing template is locked while a scheduled/queued/sending campaign uses it.
     */
    public function update(SuperAdmin $user, PlatformEmailTemplate $template): bool
    {
        return $this->hasPermission($user, $this->permissionFor($template)) && ! $template->isLockedByRunningCampaign();
    }

    /**
     * Never for a system template; for a marketing one, only if no campaign references it.
     */
    public function delete(SuperAdmin $user, PlatformEmailTemplate $template): bool
    {
        return $template->isDeletable() && $this->hasPermission($user, 'manage marketing');
    }

    private function permissionFor(PlatformEmailTemplate $template): string
    {
        return $template->isSystem() ? 'manage platform' : 'manage marketing';
    }

    private function hasPermission(SuperAdmin $user, string $permission): bool
    {
        // Super admins use the fixed sentinel team id (0) — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'super_admin')->exists()) {
            return $user->hasRole('Super Admin');
        }

        return $user->hasPermissionTo($permission);
    }
}
