<?php

namespace App\Policies;

use App\Models\EmailCampaign;
use App\Models\SuperAdmin;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * One model, two owners: tenant staff (`manage communications`) manage only their own tenant's
 * campaigns; Super Admins (`manage marketing`) manage only platform campaigns. A campaign can
 * only be edited or deleted before it starts sending.
 */
class EmailCampaignPolicy
{
    public function viewAny(TenantUser|SuperAdmin $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser|SuperAdmin $user, EmailCampaign $campaign): bool
    {
        return $this->owns($user, $campaign);
    }

    public function create(TenantUser|SuperAdmin $user): bool
    {
        return $this->canManage($user);
    }

    public function update(TenantUser|SuperAdmin $user, EmailCampaign $campaign): bool
    {
        return $campaign->isEditable() && $this->owns($user, $campaign);
    }

    public function delete(TenantUser|SuperAdmin $user, EmailCampaign $campaign): bool
    {
        return $campaign->isEditable() && $this->owns($user, $campaign);
    }

    private function owns(TenantUser|SuperAdmin $user, EmailCampaign $campaign): bool
    {
        $isOwner = $user instanceof SuperAdmin
            ? $campaign->isPlatform()
            : ! $campaign->isPlatform() && $campaign->tenant_id === $user->tenant_id;

        return $isOwner && $this->canManage($user);
    }

    private function canManage(TenantUser|SuperAdmin $user): bool
    {
        if ($user instanceof SuperAdmin) {
            // Super admins use the fixed sentinel team id (0) — see ResolvePlatformTeam.
            app(PermissionRegistrar::class)->setPermissionsTeamId(0);

            if (! Permission::query()->where('name', 'manage marketing')->where('guard_name', 'super_admin')->exists()) {
                return $user->hasRole('Super Admin');
            }

            return $user->hasPermissionTo('manage marketing');
        }

        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'manage communications')->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo('manage communications');
    }
}
