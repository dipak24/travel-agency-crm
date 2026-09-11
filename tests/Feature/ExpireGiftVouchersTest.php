<?php

use App\Models\GiftVoucher;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function egvTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug, 'currency' => 'USD']);
}

test('the expire command marks past-due unredeemed and partially-redeemed vouchers as expired', function () {
    $tenant = egvTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);

    $expiredUnredeemed = GiftVoucher::query()->create([
        'code' => 'GV-EXPIRED1', 'value' => 5000, 'currency' => 'USD',
        'status' => 'unredeemed', 'expires_at' => now()->subDay(),
    ]);
    $expiredPartial = GiftVoucher::query()->create([
        'code' => 'GV-EXPIRED2', 'value' => 2000, 'currency' => 'USD',
        'status' => 'partially_redeemed', 'expires_at' => now()->subHour(),
    ]);
    $stillValid = GiftVoucher::query()->create([
        'code' => 'GV-VALID', 'value' => 5000, 'currency' => 'USD',
        'status' => 'unredeemed', 'expires_at' => now()->addDay(),
    ]);
    $noExpiry = GiftVoucher::query()->create([
        'code' => 'GV-NOEXPIRY', 'value' => 5000, 'currency' => 'USD',
        'status' => 'unredeemed', 'expires_at' => null,
    ]);
    $alreadyRedeemed = GiftVoucher::query()->create([
        'code' => 'GV-REDEEMED', 'value' => 0, 'currency' => 'USD',
        'status' => 'redeemed', 'expires_at' => now()->subDay(),
    ]);

    $this->artisan('app:expire-gift-vouchers')->assertSuccessful();

    expect($expiredUnredeemed->refresh()->status)->toBe('expired')
        ->and($expiredPartial->refresh()->status)->toBe('expired')
        ->and($stillValid->refresh()->status)->toBe('unredeemed')
        ->and($noExpiry->refresh()->status)->toBe('unredeemed')
        ->and($alreadyRedeemed->refresh()->status)->toBe('redeemed');
});

test('the expire command runs across every tenant, not just the current context', function () {
    $tenantA = egvTenant('Northwind Travel', 'northwind-travel');
    $tenantB = egvTenant('Southbound Travel', 'southbound-travel');

    app(TenantContext::class)->set($tenantA);
    $voucherA = GiftVoucher::query()->create([
        'code' => 'GV-TENANT-A', 'value' => 5000, 'currency' => 'USD',
        'status' => 'unredeemed', 'expires_at' => now()->subDay(),
    ]);

    app(TenantContext::class)->set($tenantB);
    $voucherB = GiftVoucher::query()->create([
        'code' => 'GV-TENANT-B', 'value' => 5000, 'currency' => 'USD',
        'status' => 'unredeemed', 'expires_at' => now()->subDay(),
    ]);

    app(TenantContext::class)->clear();

    $this->artisan('app:expire-gift-vouchers')->assertSuccessful();

    expect(GiftVoucher::withoutGlobalScopes()->whereKey($voucherA->id)->value('status'))->toBe('expired')
        ->and(GiftVoucher::withoutGlobalScopes()->whereKey($voucherB->id)->value('status'))->toBe('expired');
});
