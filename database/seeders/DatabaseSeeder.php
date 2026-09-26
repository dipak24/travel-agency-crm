<?php

namespace Database\Seeders;

use App\Enums\CustomerStatus;
use App\Models\Country;
use App\Models\Customer;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CountrySeeder::class);

        $admin = SuperAdmin::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Platform Admin', 'password' => 'password', 'status' => 'active'],
        );

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => 'demo-travel'],
            ['name' => 'Demo Travel Agency', 'billing_email' => 'billing@example.com'],
        );

        $staff = TenantUser::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'staff@example.com'],
            ['name' => 'Demo Staff', 'password' => 'password', 'status' => 'active'],
        );

        $plan = SubscriptionPlan::query()->updateOrCreate(
            ['name' => 'Starter'],
            ['price' => 0, 'billing_cycle' => 'monthly', 'feature_limits' => ['max_staff' => 5]],
        );

        app(TenantContext::class)->set($tenant);
        $staff->assignRole(Role::findOrCreate('Tenant Owner', 'tenant'));

        $customer = Customer::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'customer@example.com'],
            [
                'name' => 'Demo Customer',
                'password' => 'password',
                'phone' => '+1 555 0100',
                'type' => 'individual',
                'date_of_birth' => '1990-04-14',
                'nationality_id' => Country::query()->where('iso2', 'NP')->value('id'),
                'country_of_residence_id' => Country::query()->where('iso2', 'NP')->value('id'),
            ],
        );
        $customer->forceFill(['status' => CustomerStatus::Active, 'email_verified_at' => $customer->email_verified_at ?? now()])->save();

        TenantSubscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
            ['status' => 'trialing', 'starts_at' => now()],
        );
        app(TenantContext::class)->clear();

        $this->call([PermissionSeeder::class, EmailTemplateSeeder::class]);

        // Super admins use the fixed sentinel team id (0) — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'super_admin'));
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
