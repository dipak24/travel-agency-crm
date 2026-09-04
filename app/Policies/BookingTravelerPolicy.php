<?php

namespace App\Policies;

use App\Models\BookingTraveler;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class BookingTravelerPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser $user, BookingTraveler $traveler): bool
    {
        return $this->sameTenant($user, $traveler);
    }

    public function create(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function update(TenantUser $user, BookingTraveler $traveler): Response
    {
        return $this->sameTenant($user, $traveler)
            ? Response::allow()
            : Response::deny('Traveler belongs to another tenant.');
    }

    public function delete(TenantUser $user, BookingTraveler $traveler): Response
    {
        return $this->sameTenant($user, $traveler)
            ? Response::allow()
            : Response::deny('Traveler belongs to another tenant.');
    }

    private function canManage(TenantUser $user): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'view bookings')->where('guard_name', 'tenant')->exists()) {
            return $user->hasAnyRole(['Tenant Owner', 'Sales Agent', 'Operations']);
        }

        return $user->hasPermissionTo('view bookings');
    }

    private function sameTenant(TenantUser $user, BookingTraveler $traveler): bool
    {
        return $traveler->tenant_id === $user->tenant_id && $this->canManage($user);
    }
}
