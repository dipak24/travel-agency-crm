<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\TenantUser;
use Illuminate\Auth\Access\Response;

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
        return $this->sameTenant($user, $booking)
            ? Response::allow()
            : Response::deny('Booking belongs to another tenant.');
    }

    private function canView(TenantUser $user): bool
    {
        return $user->hasAnyRole(['Tenant Owner', 'Sales Agent', 'Operations']);
    }

    private function sameTenant(TenantUser $user, Booking $booking): bool
    {
        return $booking->tenant_id === $user->tenant_id && $this->canView($user);
    }
}
