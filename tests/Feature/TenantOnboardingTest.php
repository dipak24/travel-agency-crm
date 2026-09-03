<?php

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Services\TenantOnboarding;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('onboarding creates an isolated tenant owner and trial subscription', function () {
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Growth',
        'price' => 10000,
        'billing_cycle' => 'monthly',
    ]);

    $tenant = app(TenantOnboarding::class)->create(
        [
            'name' => 'New Travel Agency',
            'slug' => 'new-travel-agency',
            'billing_email' => 'billing@new-travel.test',
        ],
        [
            'name' => 'Agency Owner',
            'email' => 'owner@new-travel.test',
            'password' => 'password',
        ],
        $plan,
    );

    $owner = TenantUser::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->firstOrFail();
    app(TenantContext::class)->set($tenant);

    expect($owner->hasRole('Tenant Owner'))->toBeTrue()
        ->and($owner)->toBeInstanceOf(Authorizable::class)
        ->and(TenantSubscription::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->where('plan_id', $plan->getKey())->exists())->toBeTrue()
        ->and(app(TenantContext::class)->id())->toBe($tenant->getKey());

    app(TenantContext::class)->clear();
});

test('onboarding rolls back when the tenant slug already exists', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Existing Agency',
        'slug' => 'existing-agency',
    ]);

    $plan = SubscriptionPlan::query()->create(['name' => 'Starter']);

    expect(fn () => app(TenantOnboarding::class)->create(
        ['name' => 'Duplicate Agency', 'slug' => 'existing-agency'],
        ['name' => 'Duplicate Owner', 'email' => 'owner@example.test', 'password' => 'password'],
        $plan,
    ))->toThrow(QueryException::class);

    expect(TenantUser::query()->withoutGlobalScopes()->where('email', 'owner@example.test')->exists())->toBeFalse();
});
