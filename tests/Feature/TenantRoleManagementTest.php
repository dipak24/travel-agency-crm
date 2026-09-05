<?php

use App\Filament\Tenant\Resources\StaffResource\Pages\EditStaff;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\TenantOnboarding;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('a newly onboarded tenant owner immediately has every tenant permission, not an empty panel', function () {
    $this->seed();

    $plan = SubscriptionPlan::query()->create(['name' => 'Growth']);

    $tenant = app(TenantOnboarding::class)->create(
        [
            'name' => 'Fresh Agency',
            'slug' => 'fresh-agency',
            'timezone' => 'UTC',
            'currency' => 'USD',
        ],
        [
            'name' => 'Fresh Owner',
            'email' => 'fresh-owner@example.test',
            'password' => 'Sup3rSecret!',
        ],
        $plan,
    );

    $owner = TenantUser::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('email', 'fresh-owner@example.test')
        ->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    expect($owner->hasPermissionTo('view customers'))->toBeTrue()
        ->and($owner->hasPermissionTo('manage settings'))->toBeTrue()
        ->and($owner->hasPermissionTo('view staff'))->toBeTrue()
        ->and($owner->hasPermissionTo('view roles'))->toBeTrue();

    $this->actingAs($owner, 'tenant')->get('/tenant')->assertOk();
});

test('the tenant owner role cannot be edited or deleted from the tenant portal, even by the owner', function () {
    $this->seed();

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $tenantContext = app(TenantContext::class);
    $tenantContext->set($tenant);
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('team_id', $tenant->getKey())->firstOrFail();
    $tenantContext->clear();

    $this->actingAs($owner, 'tenant')
        ->get("/tenant/roles/{$ownerRole->getKey()}/edit")
        ->assertForbidden();

    $tenantContext->set($tenant);
    expect($owner->can('delete', $ownerRole))->toBeFalse();
    $tenantContext->clear();
});

test('a tenant owner cannot change their own role from the staff edit form, even by bypassing the disabled field', function () {
    $this->seed();

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $tenantContext = app(TenantContext::class);
    $tenantContext->set($tenant);

    // Livewire::test() never dispatches through the tenant panel's own route,
    // so Filament never learns which panel is "current" and Filament::auth()
    // (used by every Resource policy check) falls back to the default panel's
    // guard — set it explicitly or every authorization check here 403s.
    Filament::setCurrentPanel('tenant');

    $salesRole = Role::query()->where('name', 'Sales Agent')->where('team_id', $tenant->getKey())->firstOrFail();

    // Even if a client bypassed the disabled state in the browser, the
    // server must not persist a change to the current user's own roles.
    Livewire::actingAs($owner, 'tenant')->test(EditStaff::class, ['record' => $owner->getKey()])
        ->set('data.roles', [$salesRole->getKey()])
        ->call('save');

    expect($owner->fresh()->hasRole('Tenant Owner'))->toBeTrue()
        ->and($owner->fresh()->hasRole('Sales Agent'))->toBeFalse();

    $tenantContext->clear();
});
