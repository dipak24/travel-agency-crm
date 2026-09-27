<?php

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantSubscription;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-15 03:00:00');
});

/**
 * @param  array<string, mixed>  $subscription
 */
function billedTenant(string $slug, int $price, string $cycle, array $subscription, string $tenantStatus = 'active'): TenantSubscription
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => $tenantStatus]);
    $plan = SubscriptionPlan::query()->create(['name' => "Plan {$slug}", 'price' => $price, 'billing_cycle' => $cycle]);

    app(TenantContext::class)->set($tenant);
    $record = TenantSubscription::query()->create(array_merge(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMonth()], $subscription));
    app(TenantContext::class)->clear();

    return $record;
}

function invoicesFor(TenantSubscription $subscription): Collection
{
    return TenantInvoice::query()->withoutGlobalScopes()->with(['items' => fn ($query) => $query->withoutGlobalScopes()])
        ->where('tenant_subscription_id', $subscription->id)->orderBy('id')->get();
}

test('a due subscription is invoiced for one period and its next billing date moves on a month', function () {
    $subscription = billedTenant('northwind', 4900, 'monthly', ['next_billing_at' => '2026-10-01 00:00:00']);

    $this->artisan('app:bill-subscriptions')->expectsOutput('Created 1 subscription invoice(s).')->assertSuccessful();

    $invoice = invoicesFor($subscription)->sole();
    $item = $invoice->items->sole();

    expect($invoice->status)->toBe('issued')
        ->and($invoice->total)->toBe(4900)
        ->and($invoice->due_date->toDateString())->toBe('2026-10-29')
        ->and($item->type)->toBe('subscription')
        ->and($item->period_start->toDateString())->toBe('2026-10-01')
        ->and($item->period_end->toDateString())->toBe('2026-10-31')
        ->and($subscription->fresh()->next_billing_at->toDateString())->toBe('2026-11-01');
});

test('running the billing twice does not invoice the same period twice', function () {
    $subscription = billedTenant('northwind', 4900, 'monthly', ['next_billing_at' => '2026-10-01 00:00:00']);

    $this->artisan('app:bill-subscriptions');
    $this->artisan('app:bill-subscriptions')->expectsOutput('Created 0 subscription invoice(s).');

    expect(invoicesFor($subscription))->toHaveCount(1);
});

test('missed periods are caught up with one invoice each', function () {
    $subscription = billedTenant('northwind', 4900, 'monthly', ['next_billing_at' => '2026-08-01 00:00:00']);

    $this->artisan('app:bill-subscriptions');

    expect(invoicesFor($subscription)->map(fn (TenantInvoice $invoice): string => $invoice->items->sole()->period_start->toDateString())->all())
        ->toBe(['2026-08-01', '2026-09-01', '2026-10-01'])
        ->and($subscription->fresh()->next_billing_at->toDateString())->toBe('2026-11-01');
});

test('a yearly plan advances a year', function () {
    $subscription = billedTenant('northwind', 49000, 'yearly', ['next_billing_at' => '2026-10-10 00:00:00']);

    $this->artisan('app:bill-subscriptions');

    expect($subscription->fresh()->next_billing_at->toDateString())->toBe('2027-10-10')
        ->and(invoicesFor($subscription)->sole()->items->sole()->period_end->toDateString())->toBe('2027-10-09');
});

test('free plans advance without an invoice', function () {
    $subscription = billedTenant('northwind', 0, 'monthly', ['next_billing_at' => '2026-10-01 00:00:00']);

    $this->artisan('app:bill-subscriptions');

    expect(invoicesFor($subscription))->toBeEmpty()
        ->and($subscription->fresh()->next_billing_at->toDateString())->toBe('2026-11-01');
});

test('cancelled subscriptions and suspended tenants are not invoiced', function () {
    $cancelled = billedTenant('cancelled', 4900, 'monthly', ['status' => 'cancelled', 'next_billing_at' => '2026-10-01 00:00:00']);
    $suspended = billedTenant('suspended', 4900, 'monthly', ['next_billing_at' => '2026-10-01 00:00:00'], 'suspended');

    $this->artisan('app:bill-subscriptions')->expectsOutput('Created 0 subscription invoice(s).');

    expect(invoicesFor($cancelled))->toBeEmpty()
        ->and(invoicesFor($suspended))->toBeEmpty();
});

test('a subscription without a billing date starts billing at the end of its trial, never retroactively', function () {
    $subscription = billedTenant('northwind', 4900, 'monthly', ['status' => 'trialing', 'next_billing_at' => null]);
    Tenant::query()->whereKey($subscription->tenant_id)->update(['trial_ends_at' => '2026-10-20 00:00:00']);
    $withoutTrial = billedTenant('westwind', 4900, 'monthly', ['next_billing_at' => null, 'starts_at' => '2026-01-01 00:00:00']);

    $this->artisan('app:bill-subscriptions');

    expect(invoicesFor($subscription))->toBeEmpty()
        ->and($subscription->fresh()->next_billing_at->toDateString())->toBe('2026-10-20')
        ->and(invoicesFor($withoutTrial))->toHaveCount(1)
        ->and($withoutTrial->fresh()->next_billing_at->toDateString())->toBe('2026-11-15');
});
