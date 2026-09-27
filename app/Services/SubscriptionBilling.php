<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantSubscription;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raises the recurring platform invoice for every subscription whose `next_billing_at` has passed,
 * then moves `next_billing_at` on by one billing cycle. Run daily by `app:bill-subscriptions`.
 *
 * - One invoice per billing period; missed periods (the scheduler was down) are caught up, up to
 *   MAX_PERIODS_PER_RUN per subscription per run.
 * - The subscription row is locked while it is billed, so overlapping runs can't double-bill.
 * - Free plans (price 0) just advance the date — no zero-value invoices.
 * - Subscriptions with no `next_billing_at` yet start billing at the end of the tenant's trial, or
 *   now — never retroactively.
 * - Cancelled/ended subscriptions and suspended/deleted tenants are skipped.
 */
class SubscriptionBilling
{
    public const MAX_PERIODS_PER_RUN = 12;

    public const PAYMENT_TERMS_DAYS = 14;

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return int the number of invoices created
     */
    public function billDue(): int
    {
        $this->scheduleFirstBilling();

        $created = 0;

        $dueIds = TenantSubscription::query()->withoutGlobalScopes()
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('id');

        foreach ($dueIds as $subscriptionId) {
            $created += $this->bill($subscriptionId);
        }

        return $created;
    }

    public static function firstBillingDate(?Tenant $tenant): CarbonInterface
    {
        $trialEndsAt = $tenant?->trial_ends_at;

        return $trialEndsAt !== null && $trialEndsAt->isFuture() ? $trialEndsAt : now();
    }

    private function scheduleFirstBilling(): void
    {
        TenantSubscription::query()->withoutGlobalScopes()
            ->whereNull('next_billing_at')
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->get()
            ->each(function (TenantSubscription $subscription): void {
                $tenant = Tenant::query()->find($subscription->tenant_id);

                $subscription->forceFill(['next_billing_at' => self::firstBillingDate($tenant)])->saveQuietly();
            });
    }

    private function bill(int $subscriptionId): int
    {
        return DB::transaction(function () use ($subscriptionId): int {
            $subscription = TenantSubscription::query()->withoutGlobalScopes()
                ->with('plan')
                ->lockForUpdate()
                ->find($subscriptionId);

            $tenant = $subscription === null ? null : Tenant::query()->find($subscription->tenant_id);

            if ($subscription === null || $tenant === null || $tenant->status === 'suspended' || $subscription->plan === null) {
                return 0;
            }

            $created = 0;
            $periods = 0;

            while ($subscription->next_billing_at !== null && $subscription->next_billing_at->lte(now()) && $periods < self::MAX_PERIODS_PER_RUN) {
                $periodStart = $subscription->next_billing_at->copy();
                $nextBillingAt = $this->addCycle($periodStart, $subscription->plan->billing_cycle);

                if ($subscription->plan->price > 0) {
                    $this->createInvoice($tenant, $subscription, $periodStart, $nextBillingAt->copy()->subDay());
                    $created++;
                }

                $subscription->forceFill(['next_billing_at' => $nextBillingAt])->saveQuietly();
                $periods++;
            }

            return $created;
        });
    }

    private function createInvoice(Tenant $tenant, TenantSubscription $subscription, Carbon $periodStart, Carbon $periodEnd): void
    {
        $plan = $subscription->plan;
        $cycle = $plan->billing_cycle === 'yearly' ? 'Yearly' : 'Monthly';

        $this->tenantContext->set($tenant);

        try {
            $invoice = TenantInvoice::query()->create([
                'tenant_subscription_id' => $subscription->getKey(),
                'status' => 'issued',
                'currency' => 'USD',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(self::PAYMENT_TERMS_DAYS)->toDateString(),
                'notes' => 'Generated automatically for your subscription.',
            ]);

            $invoice->items()->create([
                'type' => 'subscription',
                'description' => "{$plan->name} plan — {$cycle} ({$periodStart->format('M j, Y')} – {$periodEnd->format('M j, Y')})",
                'qty' => 1,
                'unit_price' => $plan->price,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ]);

            $invoice->refreshTotals();
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function addCycle(Carbon $date, string $billingCycle): Carbon
    {
        return $billingCycle === 'yearly' ? $date->copy()->addYearNoOverflow() : $date->copy()->addMonthNoOverflow();
    }
}
