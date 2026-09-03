<?php

use App\Models\Customer;
use App\Models\SuperAdmin;
use App\Models\TenantUser;
use App\Models\Service;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('seeded tenant staff can authenticate with the documented credentials', function () {
    $this->seed();

    expect(auth('tenant')->attempt([
        'email' => 'staff@example.com',
        'password' => 'password',
    ]))->toBeTrue()
        ->and(auth('tenant')->user())->toBeInstanceOf(TenantUser::class)
        ->and(auth('tenant')->user())->toBeInstanceOf(Authorizable::class);
});

test('seeded admin and portal accounts can authenticate with their documented credentials', function () {
    $this->seed();

    expect(auth('super_admin')->attempt([
        'email' => 'admin@example.com',
        'password' => 'password',
    ]))->toBeTrue()
        ->and(auth('super_admin')->user())->toBeInstanceOf(SuperAdmin::class);

    auth('super_admin')->logout();

    expect(auth('customer')->attempt([
        'email' => 'customer@example.com',
        'password' => 'password',
    ]))->toBeTrue()
        ->and(auth('customer')->user())->toBeInstanceOf(Customer::class);
});

test('database seed assigns permissions to platform and tenant roles', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();
    app(PermissionRegistrar::class)->setPermissionsTeamId($staff->tenant_id);

    expect($staff->hasRole('Tenant Owner'))
        ->toBeTrue()
        ->and($staff->hasPermissionTo('view bookings'))->toBeTrue()
        ->and($staff->hasPermissionTo('create catalog'))->toBeTrue()
        ->and(Permission::query()->where('name', 'manage platform')->where('guard_name', 'super_admin')->exists())
        ->toBeTrue();
});

test('seeded accounts can access their separate Filament panels', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();
    $customer = Customer::query()->withoutGlobalScopes()
        ->where('email', 'customer@example.com')
        ->firstOrFail();

    $this->actingAs($admin, 'super_admin')->get('/admin')->assertOk();
    $this->actingAs($staff, 'tenant')->get('/tenant')->assertOk();
    $this->actingAs($customer, 'customer')->get('/portal')->assertOk();
});

test('tenant staff can access the services resource', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    $this->actingAs($staff, 'tenant')
        ->get('/tenant/services')
        ->assertOk();
});

test('tenant-owned catalog create pages resolve the authenticated tenant', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    foreach ([
        '/tenant/include-excludes/create',
        '/tenant/fixed-departures/create',
        '/tenant/services/create',
    ] as $path) {
        $this->actingAs($staff, 'tenant')
            ->get($path)
            ->assertOk();
    }
});

test('seeded tenant owner can manage catalog records', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();
    app(TenantContext::class)->set($staff->tenant);
    $service = Service::query()->withoutGlobalScopes()->create([
        'tenant_id' => $staff->tenant_id,
        'name' => 'Airport transfer',
        'price' => 2500,
        'currency' => 'USD',
        'is_active' => true,
        'has_limited_availability' => false,
    ]);

    expect(Gate::forUser($staff)->allows('create', Service::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $service))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('delete', $service))->toBeTrue();
});
