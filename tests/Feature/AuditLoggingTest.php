<?php

use App\Models\Booking;
use App\Models\BookingTraveler;
use App\Models\Customer;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;

uses(RefreshDatabase::class);

function auditTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('creating a booking logs an activity attributed to the acting tenant staff member', function () {
    $tenant = auditTenant('Northwind Travel', 'northwind-travel');

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $this->actingAs($owner, 'tenant');

    $booking = Booking::query()->create([
        'customer_id' => Customer::factory()->create()->id,
        'trip_name' => 'Alpine Escape',
    ]);

    $activity = Activity::query()->where('subject_type', Booking::class)->where('subject_id', $booking->id)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('booking')
        ->and($activity->event)->toBe('created')
        ->and($activity->causer_type)->toBe(TenantUser::class)
        ->and($activity->causer_id)->toBe($owner->id)
        ->and($activity->attribute_changes->get('attributes')['trip_name'])->toBe('Alpine Escape');
});

test('a booking traveler activity never includes the encrypted passport number', function () {
    $tenant = auditTenant('Northwind Travel', 'northwind-travel');

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $this->actingAs($owner, 'tenant');

    $booking = Booking::query()->create([
        'customer_id' => Customer::factory()->create()->id,
        'trip_name' => 'Alpine Escape',
    ]);
    $traveler = $booking->travelers()->create([
        'name' => 'Asha Traveler',
        'passport_no' => 'P1234567',
        'document_status' => 'pending',
    ]);

    $activity = Activity::query()->where('subject_type', BookingTraveler::class)->where('subject_id', $traveler->id)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('booking_traveler')
        ->and($activity->attribute_changes->get('attributes'))->not->toHaveKey('passport_no');
});

test('the activity log causer resolver checks the super_admin, tenant, and customer guards in order', function () {
    $tenant = auditTenant('Northwind Travel', 'northwind-travel');

    expect(app(CauserResolver::class)->resolve())->toBeNull();

    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create();
    $this->actingAs($staff, 'tenant');

    expect(app(CauserResolver::class)->resolve()?->is($staff))->toBeTrue();

    $admin = SuperAdmin::factory()->create();
    $this->actingAs($admin, 'super_admin');

    // super_admin is checked first, so it takes priority even though the
    // tenant guard above is still authenticated in the same test process.
    expect(app(CauserResolver::class)->resolve()?->is($admin))->toBeTrue();
});
