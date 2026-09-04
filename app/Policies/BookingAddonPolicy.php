<?php

namespace App\Policies;

use App\Models\BookingAddon;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class BookingAddonPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser $user, BookingAddon $addon): bool
    {
        return $this->sameTenant($user, $addon);
    }

    public function create(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function update(TenantUser $user, BookingAddon $addon): Response
    {
        return $this->sameTenant($user, $addon)
            ? Response::allow()
            : Response::deny('Add-on belongs to another tenant.');
    }

    public function delete(TenantUser $user, BookingAddon $addon): Response
    {
        return $this->sameTenant($user, $addon)
            ? Response::allow()
            : Response::deny('Add-on belongs to another tenant.');
    }

    private function canManage(TenantUser $user): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'view bookings')->where('guard_name', 'tenant')->exists()) {
            return $user->hasAnyRole(['Tenant Owner', 'Sales Agent', 'Operations']);
        }

        return $user->hasPermissionTo('view bookings');
    }

    private function sameTenant(TenantUser $user, BookingAddon $addon): bool
    {
        return $addon->tenant_id === $user->tenant_id && $this->canManage($user);
    }
}
