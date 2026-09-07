<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class BookingPolicy
{
    /**
     * Also used by the `customer` guard for the portal's read-only booking
     * list/detail pages — a customer can always see their own booking list
     * (the resource's own query scopes rows to `customer_id`), they just
     * can't create/update/delete, which the portal `BookingResource`
     * hardcodes closed rather than routing through this policy.
     */
    public function viewAny(TenantUser|Customer $user): bool
    {
        return $user instanceof Customer || $this->canView($user);
    }

    public function view(TenantUser|Customer $user, Booking $booking): bool
    {
        if ($user instanceof Customer) {
            return $user->tenant_id === $booking->tenant_id && $user->id === $booking->customer_id;
        }

        return $this->sameTenant($user, $booking);
    }

    public function update(TenantUser $user, Booking $booking): Response
    {
        return $this->sameTenant($user, $booking, 'update bookings')
            ? Response::allow()
            : Response::deny('Booking belongs to another tenant.');
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create bookings');
    }

    private function canView(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'view bookings');
    }

    private function sameTenant(TenantUser $user, Booking $booking, string $permission = 'view bookings'): bool
    {
        return $booking->tenant_id === $user->tenant_id && $this->hasPermission($user, $permission);
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
