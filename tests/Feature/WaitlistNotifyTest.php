<?php

use App\Filament\Tenant\Resources\BookingWaitlistResource\Pages\ListBookingWaitlists;
use App\Models\BookingWaitlist;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\WaitlistSeatAvailable;
use App\Services\Mail\TenantMailer;
use App\Services\WaitlistNotifier;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function waitlistEntry(Tenant $tenant, array $attributes = []): BookingWaitlist
{
    return app(TenantContext::class)->wrap($tenant, function () use ($attributes): BookingWaitlist {
        $package = Package::query()->create(['name' => 'Everest Base Camp', 'slug' => 'ebc', 'package_code' => 'EBC', 'duration_days' => 12]);
        $departure = FixedDeparture::query()->create([
            'package_id' => $package->id, 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDays(11),
            'total_slots' => 10, 'booked_slots' => 10, 'status' => 'full',
        ]);

        return BookingWaitlist::query()->create([
            'fixed_departure_id' => $departure->id,
            'customer_id' => Customer::factory()->create(['email' => 'asha@example.com'])->id,
            'pax_requested' => 2,
            'position' => 1,
            'status' => 'waiting',
            ...$attributes,
        ]);
    });
}

function waitlistStaff(Tenant $tenant, string $role = 'Tenant Owner'): TenantUser
{
    return app(TenantContext::class)->wrap($tenant, function () use ($tenant, $role): TenantUser {
        $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'tenant', 'team_id' => $tenant->id]));

        return $user;
    });
}

test('staff can notify a waiting customer that seats are available', function () {
    Notification::fake();
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $staff = waitlistStaff($tenant);
    $entry = waitlistEntry($tenant);
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($staff, 'tenant')->test(ListBookingWaitlists::class)
        ->assertCanSeeTableRecords([$entry])
        ->callAction(TestAction::make('notify')->table($entry));

    Notification::assertSentTo(Customer::query()->withoutGlobalScopes()->find($entry->customer_id), WaitlistSeatAvailable::class);
    expect($entry->fresh()->only(['status', 'notified_by_staff_id']))->toBe(['status' => 'notified', 'notified_by_staff_id' => $staff->id])
        ->and($entry->fresh()->notified_at)->not->toBeNull();
});

test('the notify action is only offered for waiting entries', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $staff = waitlistStaff($tenant);
    $entry = waitlistEntry($tenant, ['status' => 'notified']);
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($staff, 'tenant')->test(ListBookingWaitlists::class)
        ->filterTable('status', 'notified')
        ->assertActionHidden(TestAction::make('notify')->table($entry));
});

test('an entry stays waiting when the email could not be delivered', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $staff = waitlistStaff($tenant);
    $entry = waitlistEntry($tenant);
    $this->mock(TenantMailer::class)->shouldReceive('send')->andReturnFalse();

    $delivered = app(TenantContext::class)->wrap($tenant, fn (): bool => app(WaitlistNotifier::class)->notify($entry->load('customer'), $staff));

    expect($delivered)->toBeFalse()
        ->and($entry->fresh()->status)->toBe('waiting');
});

test('staff without booking access cannot open the waitlist', function () {
    $this->seed();
    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $accountant = waitlistStaff($tenant, 'Accountant');

    $this->actingAs($accountant, 'tenant')->get('/tenant/booking-waitlists')->assertForbidden();
});

test('the waitlist and reminder settings pages render for a tenant owner', function () {
    $this->seed();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $this->actingAs($owner, 'tenant')->get('/tenant/booking-waitlists')->assertOk();
    $this->actingAs($owner, 'tenant')->get('/tenant/reminder-settings')->assertOk()->assertSee('Days before the trip starts');
});
