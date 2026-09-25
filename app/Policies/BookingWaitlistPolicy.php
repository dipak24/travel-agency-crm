<?php

namespace App\Policies;

use App\Models\BookingWaitlist;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Spatie\Permission\Models\Permission;

/**
 * Waitlist entries are created by visitors through the public API, never by staff — so there's
 * no create ability. Viewing follows `view bookings`; notifying or updating follows `update bookings`.
 */
class BookingWaitlistPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'view bookings');
    }

    public function view(TenantUser $user, BookingWaitlist $entry): bool
    {
        return $entry->tenant_id === $user->tenant_id && $this->hasPermission($user, 'view bookings');
    }

    public function create(TenantUser $user): bool
    {
        return false;
    }

    public function update(TenantUser $user, BookingWaitlist $entry): bool
    {
        return $entry->tenant_id === $user->tenant_id && $this->hasPermission($user, 'update bookings');
    }

    public function delete(TenantUser $user, BookingWaitlist $entry): bool
    {
        return false;
    }

    private function hasPermission(TenantUser $user, string $permission): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'tenant')->exists()) {
            return $user->hasAnyRole(['Tenant Owner', 'Sales Agent', 'Operations']);
        }

        return $user->hasPermissionTo($permission);
    }
}
