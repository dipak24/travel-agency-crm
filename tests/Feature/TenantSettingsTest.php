<?php

use App\Filament\Tenant\Pages\Settings;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a tenant owner can access and save the settings page', function () {
    $this->seed();

    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $this->actingAs($owner, 'tenant');
    expect(Settings::canAccess())->toBeTrue();

    $test = Livewire::actingAs($owner, 'tenant')->test(Settings::class);

    $test->set('data.name', 'Renamed Demo Agency')
        ->set('data.timezone', 'Asia/Kathmandu')
        ->set('data.currency', 'NPR')
        ->set('data.billing_email', 'new-billing@example.com')
        ->set('data.brand_color', '#112233')
        ->call('save');

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();

    expect($tenant->name)->toBe('Renamed Demo Agency')
        ->and($tenant->timezone)->toBe('Asia/Kathmandu')
        ->and($tenant->currency)->toBe('NPR')
        ->and($tenant->billing_email)->toBe('new-billing@example.com')
        ->and($tenant->brand_color)->toBe('#112233');
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
