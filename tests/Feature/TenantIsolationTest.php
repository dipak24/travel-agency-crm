<?php

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('tenant-owned queries cannot cross tenant boundaries', function () {
    $firstTenant = Tenant::query()->create(['name' => 'First Agency', 'slug' => 'first-agency']);
    $secondTenant = Tenant::query()->create(['name' => 'Second Agency', 'slug' => 'second-agency']);

    app(TenantContext::class)->set($firstTenant);
    TenantUser::query()->create([
        'name' => 'First Staff',
        'email' => 'staff@example.com',
        'password' => 'password',
    ]);

    app(TenantContext::class)->set($secondTenant);

    expect(TenantUser::query()->count())->toBe(0);
    expect(TenantUser::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('tenant-owned records cannot be created without a tenant context', function () {
    expect(fn () => TenantUser::query()->create([
        'name' => 'Unscoped Staff',
        'email' => 'unscoped@example.com',
        'password' => 'password',
    ]))->toThrow(LogicException::class);
});
