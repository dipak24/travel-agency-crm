<?php

use App\Filament\Tenant\Pages\Settings;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a tenant owner can access the settings page and save billing details', function () {
    $this->seed();

    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $this->actingAs($owner, 'tenant');
    expect(Settings::canAccess())->toBeTrue();

    $test = Livewire::actingAs($owner, 'tenant')->test(Settings::class);

    $test->set('data.currency', 'NPR')
        ->set('data.billing_email', 'new-billing@example.com')
        ->call('save');

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();

    expect($tenant->currency)->toBe('NPR')
        ->and($tenant->billing_email)->toBe('new-billing@example.com');
});

test('business details fields are disabled and cannot be changed from the settings page', function () {
    $this->seed();

    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
    $originalName = Tenant::query()->where('slug', 'demo-travel')->value('name');

    $test = Livewire::actingAs($owner, 'tenant')->test(Settings::class);

    // Even if a client bypassed the disabled state in the browser, the
    // server must not persist changes to fields disabled on the backend.
    $test->set('data.name', 'Hacked Agency Name')
        ->set('data.timezone', 'Asia/Kathmandu')
        ->call('save');

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();

    expect($tenant->name)->toBe($originalName)
        ->and($tenant->timezone)->toBe('UTC');
});

test('the settings page does not expose branding color fields', function () {
    $this->seed();

    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    Livewire::actingAs($owner, 'tenant')->test(Settings::class)
        ->assertDontSee('Primary color')
        ->assertDontSee('Secondary color');
});

test('a tenant staff member without the manage settings permission is denied access', function () {
    $this->seed();

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();

    $tenantContext = app(TenantContext::class);
    $tenantContext->set($tenant);

    $salesAgent = TenantUser::factory()->create();
    $salesAgent->assignRole(Role::findOrCreate('Sales Agent', 'tenant'));

    $tenantContext->clear();

    $this->actingAs($salesAgent, 'tenant');

    expect(Settings::canAccess())->toBeFalse();
});
