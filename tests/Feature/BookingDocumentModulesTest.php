<?php

use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingDocument;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Policies\BookingAddonPolicy;
use App\Policies\BookingDocumentPolicy;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function bookingDocsTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('booking documents and add-ons stay tenant scoped and reviewable', function () {
    $tenant = bookingDocsTenant('First Agency', 'first-agency');
    $otherTenant = bookingDocsTenant('Second Agency', 'second-agency');

    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create();
    $role = Role::create([
        'name' => 'Operations',
        'guard_name' => 'tenant',
        'team_id' => $tenant->id,
    ]);
    $staff->assignRole($role);

    $booking = Booking::query()->create([
        'customer_id' => Customer::factory()->create()->id,
        'trip_name' => 'Document check trip',
    ]);
    $document = BookingDocument::query()->create([
        'booking_id' => $booking->id,
        'doc_type' => 'passport',
        'file_path' => 'booking-documents/private/passport.pdf',
        'status' => 'pending',
        'uploaded_by' => 'agent@example.com',
    ]);
    $service = \App\Models\Service::query()->create([
        'name' => 'Airport transfer',
        'description' => 'Private transfer to airport',
        'price' => 3500,
    ]);
    $addon = BookingAddon::query()->create([
        'booking_id' => $booking->id,
        'service_id' => $service->id,
        'price' => 3500,
        'quantity' => 1,
        'status' => 'requested',
        'added_by' => 'agent@example.com',
    ]);

    expect((new BookingDocumentPolicy)->view($staff, $document))->toBeTrue()
        ->and((new BookingAddonPolicy)->view($staff, $addon))->toBeTrue();

    app(TenantContext::class)->set($otherTenant);

    expect(BookingDocument::query()->find($document->id))->toBeNull()
        ->and(BookingAddon::query()->find($addon->id))->toBeNull();
});
