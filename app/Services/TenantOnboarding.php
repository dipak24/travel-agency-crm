<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class TenantOnboarding
{
    /**
     * @param  array{name: string, slug: string, billing_email?: ?string, timezone?: string, currency?: string, logo?: ?string, brand_color?: ?string}  $tenantData
     * @param  array{name: string, email: string, password: string}  $ownerData
     */
    public function create(array $tenantData, array $ownerData, SubscriptionPlan $plan): Tenant
    {
        return DB::transaction(function () use ($tenantData, $ownerData, $plan): Tenant {
            $tenant = Tenant::query()->create($tenantData);
            $tenantContext = app(TenantContext::class);
            $tenantContext->set($tenant);

            try {
                $owner = TenantUser::query()->create([
                    ...$ownerData,
                    'status' => 'active',
                ]);

                $role = Role::findOrCreate('Tenant Owner', 'tenant');
                $owner->assignRole($role);

                TenantSubscription::query()->create([
                    'plan_id' => $plan->getKey(),
                    'status' => 'trialing',
                    'starts_at' => now(),
                ]);

                return $tenant;
            } finally {
                $tenantContext->clear();
            }
        });
    }
}
