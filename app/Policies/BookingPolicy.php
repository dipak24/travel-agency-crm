<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class BookingPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canView($user);
    }

    public function view(TenantUser $user, Booking $booking): bool
    {
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
