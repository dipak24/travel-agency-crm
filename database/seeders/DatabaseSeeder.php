<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        SuperAdmin::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Platform Admin', 'password' => 'password', 'status' => 'active'],
        );

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => 'demo-travel'],
            ['name' => 'Demo Travel Agency', 'billing_email' => 'billing@example.com'],
        );

        $tenantUser = TenantUser::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'staff@example.com'],
            ['name' => 'Demo Staff', 'password' => 'password', 'status' => 'active'],
        );

        $plan = SubscriptionPlan::query()->updateOrCreate(
            ['name' => 'Starter'],
            ['price' => 0, 'billing_cycle' => 'monthly', 'feature_limits' => ['max_staff' => 5]],
        );

        app(TenantContext::class)->set($tenant);
        TenantSubscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
            ['status' => 'trialing', 'starts_at' => now()],
        );
        app(TenantContext::class)->clear();
    }
}
