<?php

use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Resources\TenantResource\Pages\ListTenants;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * fillForm() doesn't persist state on this project's exact Filament/Livewire
 * combination — see .ai/rules/feature.md. Route around it with direct set().
 */
function fillTenantForm(Testable $test, array $data): Testable
{
    foreach ($data as $key => $value) {
        $test->set("data.{$key}", $value);
    }

    return $test;
}

test('seeded platform admin can access the tenant resource pages', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->actingAs($admin, 'super_admin')->get('/admin/tenants')->assertOk();
    $this->actingAs($admin, 'super_admin')->get('/admin/tenants/create')->assertOk();
});

test('a platform admin without the manage tenants permission is denied access', function () {
    $this->seed();

    $unprivileged = SuperAdmin::factory()->create();

    expect(Gate::forUser($unprivileged)->allows('viewAny', Tenant::class))->toBeFalse()
        ->and(Gate::forUser($unprivileged)->allows('create', Tenant::class))->toBeFalse();
});

test('a platform admin can create a tenant with an owner and a subscription plan', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $plan = SubscriptionPlan::query()->create(['name' => 'Growth', 'price' => 10000, 'billing_cycle' => 'monthly']);

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateTenant::class);

    fillTenantForm($test, [
        'name' => 'New Travel Agency',
        'slug' => 'new-travel-agency',
        'status' => 'trial',
        'billing_email' => 'billing@new-travel.test',
        'timezone' => 'UTC',
        'currency' => 'USD',
        'primary_color' => '#ff5500',
        'secondary_color' => '#0055ff',
        'owner_name' => 'Agency Owner',
        'owner_email' => 'owner@new-travel.test',
        'owner_password' => 'Sup3rSecret!',
        'plan_id' => $plan->getKey(),
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'new-travel-agency')->firstOrFail();

    expect($tenant->primary_color)->toBe('#ff5500')
        ->and($tenant->secondary_color)->toBe('#0055ff');

    $owner = TenantUser::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('email', 'owner@new-travel.test')
        ->firstOrFail();

    app(TenantContext::class)->set($tenant);
    expect($owner->hasRole('Tenant Owner'))->toBeTrue();
    app(TenantContext::class)->clear();

    expect(TenantSubscription::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('plan_id', $plan->getKey())
        ->exists())->toBeTrue();
});

test('creating a tenant rejects an owner email already used by staff in another tenant', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $plan = SubscriptionPlan::query()->create(['name' => 'Growth']);

    // Staff sign in from one shared login page, so email must be unique
    // platform-wide — not just within a single tenant.
    $existingTenant = Tenant::factory()->create();
    app(TenantContext::class)->set($existingTenant);
    TenantUser::query()->create([
        'name' => 'Existing Staffer',
        'email' => 'shared@example.test',
        'password' => 'password',
    ]);
    app(TenantContext::class)->clear();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateTenant::class);

    fillTenantForm($test, [
        'name' => 'Another Travel Agency',
        'slug' => 'another-travel-agency',
        'status' => 'trial',
        'timezone' => 'UTC',
        'currency' => 'USD',
        'owner_name' => 'Agency Owner',
        'owner_email' => 'shared@example.test',
        'owner_password' => 'Sup3rSecret!',
        'plan_id' => $plan->getKey(),
    ])
        ->call('create')
        ->assertHasFormErrors(['owner_email' => 'unique']);

    expect(Tenant::query()->where('slug', 'another-travel-agency')->exists())->toBeFalse();
});

test('creating a tenant rejects billing email, phone, or mobile number already used by another tenant', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $plan = SubscriptionPlan::query()->create(['name' => 'Growth']);
    $existingTenant = Tenant::factory()->create([
        'billing_email' => 'shared-billing@example.test',
        'phone_number' => '+1-555-0100',
        'mobile_number' => '+1-555-0101',
    ]);

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateTenant::class);

    fillTenantForm($test, [
        'name' => 'Duplicate Details Agency',
        'slug' => 'duplicate-details-agency',
        'status' => 'trial',
        'billing_email' => $existingTenant->billing_email,
        'phone_number' => $existingTenant->phone_number,
        'mobile_number' => $existingTenant->mobile_number,
        'timezone' => 'UTC',
        'currency' => 'USD',
        'owner_name' => 'Agency Owner',
        'owner_email' => 'owner@duplicate-details.test',
        'owner_password' => 'Sup3rSecret!',
        'plan_id' => $plan->getKey(),
    ])
        ->call('create')
        ->assertHasFormErrors(['billing_email' => 'unique', 'phone_number' => 'unique', 'mobile_number' => 'unique']);

    expect(Tenant::query()->where('slug', 'duplicate-details-agency')->exists())->toBeFalse();
});

test('creating a tenant rejects a trial end date in the past', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $plan = SubscriptionPlan::query()->create(['name' => 'Growth']);

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateTenant::class);

    fillTenantForm($test, [
        'name' => 'Past Trial Agency',
        'slug' => 'past-trial-agency',
        'status' => 'trial',
        'timezone' => 'UTC',
        'currency' => 'USD',
        'trial_ends_at' => now()->subDay(),
        'owner_name' => 'Agency Owner',
        'owner_email' => 'owner@past-trial.test',
        'owner_password' => 'Sup3rSecret!',
        'plan_id' => $plan->getKey(),
    ])
        ->call('create')
        ->assertHasFormErrors(['trial_ends_at']);

    expect(Tenant::query()->where('slug', 'past-trial-agency')->exists())->toBeFalse();
});

test('a platform admin can edit a tenant and reassign its subscription plan', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $tenant = Tenant::factory()->create(['status' => 'trial']);
    $originalPlan = SubscriptionPlan::query()->create(['name' => 'Starter']);
    $newPlan = SubscriptionPlan::query()->create(['name' => 'Growth']);

    $tenantContext = app(TenantContext::class);
    $tenantContext->set($tenant);
    $subscription = TenantSubscription::query()->create([
        'plan_id' => $originalPlan->getKey(),
        'status' => 'trialing',
        'starts_at' => now(),
    ]);
    $tenantContext->clear();

    $test = Livewire::actingAs($admin, 'super_admin')->test(EditTenant::class, ['record' => $tenant->getKey()]);

    expect((int) $test->get('data.plan_id'))->toBe($originalPlan->getKey());

    fillTenantForm($test, [
        'name' => 'Renamed Agency',
        'status' => 'active',
        'plan_id' => $newPlan->getKey(),
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    $tenant->refresh();
    $subscription->refresh();

    expect($tenant->name)->toBe('Renamed Agency')
        ->and($tenant->status)->toBe('active')
        ->and($subscription->plan_id)->toBe($newPlan->getKey());
});

test('a platform admin can suspend and reactivate a tenant', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $tenant = Tenant::factory()->active()->create();

    Livewire::actingAs($admin, 'super_admin')->test(ListTenants::class)
        ->callAction(TestAction::make('suspend')->table($tenant));

    expect($tenant->refresh()->status)->toBe('suspended');

    Livewire::actingAs($admin, 'super_admin')->test(ListTenants::class)
        ->callAction(TestAction::make('reactivate')->table($tenant));

    expect($tenant->refresh()->status)->toBe('active');
});

test('a platform admin can soft-delete a tenant', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($admin, 'super_admin')->test(ListTenants::class)
        ->callAction(TestAction::make('delete')->table($tenant));

    expect(Tenant::query()->find($tenant->getKey()))->toBeNull()
        ->and(Tenant::withTrashed()->find($tenant->getKey()))->not->toBeNull();
});
