<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TenantOnboarding
{
    /**
     * @param  array{name: string, slug: string, billing_email?: ?string, timezone?: string, currency?: string, logo?: ?string, primary_color?: ?string, secondary_color?: ?string}  $tenantData
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

                // The owner is this tenant's portal super admin: it must hold
                // every tenant-guard permission that exists, or a freshly
                // created tenant's owner logs in to an empty panel (no nav
                // items pass their resource policies). PermissionSeeder only
                // wires this up for tenants that already existed at seed
                // time, so a new tenant created here needs the same sync.
                $role = Role::findOrCreate('Tenant Owner', 'tenant');
                $role->syncPermissions(Permission::query()->where('guard_name', 'tenant')->get());
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
