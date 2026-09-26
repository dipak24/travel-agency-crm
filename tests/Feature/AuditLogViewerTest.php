<?php

use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogResource\Pages\ViewActivityLog;
use App\Filament\Tenant\Resources\ActivityLogResource\Pages\ListActivityLogs as ListTenantActivityLogs;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, staff: TenantUser, booking: Booking}
 */
function auditLogBooking(string $slug, string $tripName): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Travel', 'slug' => $slug]);

    return app(TenantContext::class)->wrap($tenant, function () use ($tenant, $tripName): array {
        $staff = TenantUser::factory()->create(['tenant_id' => $tenant->id, 'name' => ucfirst($tenant->slug).' Staff']);
        auth('tenant')->setUser($staff);

        $booking = Booking::query()->create([
            'customer_id' => Customer::factory()->create()->id,
            'trip_name' => $tripName,
        ]);

        auth('tenant')->forgetUser();

        return ['tenant' => $tenant, 'staff' => $staff, 'booking' => $booking];
    });
}

function auditLogGrantTenantRole(TenantUser $staff, string $roleName, array $permissions): void
{
    app(TenantContext::class)->wrap($staff->tenant, function () use ($staff, $roleName, $permissions): void {
        $role = Role::query()->create(['name' => $roleName, 'guard_name' => 'tenant', 'team_id' => $staff->tenant_id]);
        $role->givePermissionTo(collect($permissions)->map(fn (string $name): Permission => Permission::findOrCreate($name, 'tenant')));
        $staff->assignRole($role);
    });
}

/**
 * A platform-level entry (no tenant), as a platform admin's own change would be logged.
 *
 * @param  array<string, mixed>  $attributes
 */
function auditLogPlatformEntry(SuperAdmin $admin, array $attributes = []): Activity
{
    return Activity::query()->create([
        'log_name' => 'default',
        'description' => 'updated',
        'event' => 'updated',
        'causer_type' => SuperAdmin::class,
        'causer_id' => $admin->id,
        ...$attributes,
    ]);
}

function auditLogAdmin(): SuperAdmin
{
    return SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
}

test('each activity entry records the tenant of the record it logged', function () {
    ['tenant' => $tenant, 'booking' => $booking] = auditLogBooking('northwind', 'Everest Base Camp');

    expect(Activity::query()->where('subject_type', Booking::class)->where('subject_id', $booking->id)->sole()->tenant_id)
        ->toBe($tenant->id);
});

test('the admin audit log shows only platform entries, never tenant activity', function () {
    $this->seed();
    ['tenant' => $tenant, 'booking' => $booking] = auditLogBooking('northwind', 'Everest Base Camp');
    $admin = auditLogAdmin();
    $platformEntry = auditLogPlatformEntry($admin);
    $tenantEntry = Activity::query()->where('subject_type', Booking::class)->where('subject_id', $booking->id)->sole();
    $adminEditInTenant = auditLogPlatformEntry($admin, [
        'tenant_id' => $tenant->id, 'subject_type' => Booking::class, 'subject_id' => $booking->id,
    ]);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'super_admin')->test(ListActivityLogs::class)
        ->assertCanSeeTableRecords([$platformEntry])
        ->assertCanNotSeeTableRecords([$tenantEntry, $adminEditInTenant]);

    expect(Gate::forUser($admin)->allows('view', $tenantEntry))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $adminEditInTenant))->toBeFalse();

    $this->actingAs($admin, 'super_admin')->get("/admin/audit-log/{$tenantEntry->id}")->assertNotFound();
    $this->actingAs($admin, 'super_admin')->get('/admin/audit-log')->assertOk()->assertDontSee('Northwind');
});

test('a super admin can filter platform entries by user, event and date', function () {
    $this->seed();
    $admin = auditLogAdmin();
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $otherAdmin = SuperAdmin::factory()->create();
    $recent = auditLogPlatformEntry($admin);
    $older = auditLogPlatformEntry($otherAdmin, ['event' => 'created']);
    $older->forceFill(['created_at' => now()->subDays(10)])->save();
    Filament::setCurrentPanel('admin');

    $page = Livewire::actingAs($admin, 'super_admin')->test(ListActivityLogs::class)
        ->assertCanSeeTableRecords([$recent, $older]);

    $page->filterTable('causer', SuperAdmin::class.'|'.$admin->id)
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$older])
        ->resetTableFilters();

    $page->filterTable('event', 'created')
        ->assertCanSeeTableRecords([$older])
        ->assertCanNotSeeTableRecords([$recent])
        ->resetTableFilters();

    $page->filterTable('created_at', ['from' => now()->subDay()->toDateString(), 'until' => now()->toDateString()])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$older]);
});

test('a platform entry shows who did it and exactly which fields changed', function () {
    $this->seed();
    $admin = auditLogAdmin();
    $entry = auditLogPlatformEntry($admin, [
        'attribute_changes' => ['old' => ['name' => 'Starter'], 'attributes' => ['name' => 'Starter Plus']],
    ]);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'super_admin')->test(ViewActivityLog::class, ['record' => $entry->getRouteKey()])
        ->assertSee($admin->name)
        ->assertSee('Starter')
        ->assertSee('Starter Plus');
});

test('the audit log is read-only in both portals', function () {
    $this->seed();
    ['staff' => $staff, 'booking' => $booking] = auditLogBooking('northwind', 'Everest Base Camp');
    auditLogGrantTenantRole($staff, 'Auditor', ['view audit log']);
    $tenantEntry = Activity::query()->where('subject_id', $booking->id)->sole();
    $admin = auditLogAdmin();
    $platformEntry = auditLogPlatformEntry($admin);

    expect(Gate::forUser($admin)->allows('view', $platformEntry))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Activity::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $platformEntry))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $platformEntry))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('view', $tenantEntry))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $tenantEntry))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('delete', $tenantEntry))->toBeFalse();

    $this->actingAs($admin, 'super_admin')->get('/admin/audit-log')->assertOk()->assertDontSee('/admin/audit-log/create', false);
});

test('admins without the platform permission cannot open the audit log', function () {
    $this->seed();
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $marketer = SuperAdmin::factory()->create();
    $marketer->givePermissionTo(Permission::findOrCreate('manage marketing', 'super_admin'));

    $this->actingAs($marketer, 'super_admin')->get('/admin/audit-log')->assertForbidden();
});

test('tenant staff cannot reach the admin audit log', function () {
    $this->seed();
    ['staff' => $staff] = auditLogBooking('northwind', 'Everest Base Camp');

    // On their agency's subdomain the Super Admin panel doesn't exist at all…
    $this->actingAsStaff($staff)->get('/admin/audit-log')->assertNotFound();
    // …and on the platform domain a staff session isn't a super admin session.
    $this->actingAs($staff, 'tenant')->get(config('app.url').'/admin/audit-log')->assertRedirect();
});

test('tenant staff see only their own tenant entries, including platform admin changes to their data', function () {
    $this->seed();
    ['tenant' => $northwind, 'staff' => $staff, 'booking' => $northwindBooking] = auditLogBooking('northwind', 'Everest Base Camp');
    ['booking' => $everestBooking] = auditLogBooking('everest', 'Annapurna Circuit');
    auditLogGrantTenantRole($staff, 'Auditor', ['view audit log']);
    $admin = auditLogAdmin();
    $ownEntry = Activity::query()->where('subject_type', Booking::class)->where('subject_id', $northwindBooking->id)->sole();
    $adminEditInTenant = auditLogPlatformEntry($admin, [
        'tenant_id' => $northwind->id, 'subject_type' => Booking::class, 'subject_id' => $northwindBooking->id,
    ]);
    $otherTenantEntry = Activity::query()->where('subject_type', Booking::class)->where('subject_id', $everestBooking->id)->sole();
    $platformEntry = auditLogPlatformEntry($admin);
    app(TenantContext::class)->set($northwind);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($staff, 'tenant')->test(ListTenantActivityLogs::class)
        ->assertCanSeeTableRecords([$ownEntry, $adminEditInTenant])
        ->assertCanNotSeeTableRecords([$otherTenantEntry, $platformEntry]);

    $this->actingAsStaff($staff)->get("/tenant/audit-log/{$ownEntry->id}")->assertOk()->assertSee('Northwind Staff');
    $this->actingAsStaff($staff)->get("/tenant/audit-log/{$otherTenantEntry->id}")->assertNotFound();
    $this->actingAsStaff($staff)->get("/tenant/audit-log/{$platformEntry->id}")->assertNotFound();
});

test('a tenant entry shows who did it and exactly which fields changed', function () {
    $this->seed();
    ['tenant' => $tenant, 'staff' => $staff, 'booking' => $booking] = auditLogBooking('northwind', 'Everest Base Camp');
    auditLogGrantTenantRole($staff, 'Auditor', ['view audit log']);
    app(TenantContext::class)->wrap($tenant, function () use ($staff, $booking): void {
        auth('tenant')->setUser($staff);
        $booking->update(['trip_name' => 'Everest Base Camp Trek']);
        auth('tenant')->forgetUser();
    });
    $entry = Activity::query()->where('subject_id', $booking->id)->where('event', 'updated')->sole();

    $this->actingAsStaff($staff)->get("/tenant/audit-log/{$entry->id}")
        ->assertOk()
        ->assertSee('Northwind Staff')
        ->assertSee('Booking #'.$booking->id)
        ->assertSee('trip_name')
        ->assertSee('Everest Base Camp Trek');
});

test('tenant staff without the audit log permission cannot open it', function () {
    $this->seed();
    ['staff' => $staff] = auditLogBooking('northwind', 'Everest Base Camp');
    auditLogGrantTenantRole($staff, 'Sales Agent', ['view bookings']);

    $this->actingAsStaff($staff)->get('/tenant/audit-log')->assertForbidden();
});

test('pruning deletes entries older than three months but keeps each record latest entry', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'booking' => $booking] = auditLogBooking('northwind', 'Everest Base Camp');
    app(TenantContext::class)->wrap($tenant, function () use ($staff, $booking): void {
        auth('tenant')->setUser($staff);
        $booking->update(['trip_name' => 'Everest Base Camp Trek']);
        auth('tenant')->forgetUser();
    });
    $created = Activity::query()->where('subject_id', $booking->id)->where('event', 'created')->sole();
    $latest = Activity::query()->where('subject_id', $booking->id)->where('event', 'updated')->sole();
    $orphan = Activity::query()->create(['log_name' => 'default', 'description' => 'old platform change']);
    $recent = Activity::query()->create(['log_name' => 'default', 'description' => 'recent platform change']);
    foreach ([$created, $latest, $orphan] as $entry) {
        $entry->forceFill(['created_at' => now()->subMonths(4)])->save();
    }
    $recent->forceFill(['created_at' => now()->subMonths(2)])->save();

    $this->artisan('model:prune', ['--model' => [Activity::class]])->assertSuccessful();

    expect(Activity::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$latest->id, $recent->id])->sort()->values()->all());
});
