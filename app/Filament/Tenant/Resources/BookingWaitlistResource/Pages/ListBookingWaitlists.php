<?php

namespace App\Filament\Tenant\Resources\BookingWaitlistResource\Pages;

use App\Filament\Tenant\Resources\BookingWaitlistResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create button — waitlist entries only come from visitors through the public API.
 */
class ListBookingWaitlists extends ListRecords
{
    protected static string $resource = BookingWaitlistResource::class;
}
