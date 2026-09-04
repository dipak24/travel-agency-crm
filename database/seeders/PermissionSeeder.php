<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Seed platform, tenant, and portal roles and permissions.
     */
    public function run(): void
    {
        $this->seedPlatformPermissions();

        foreach (Tenant::query()->get() as $tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
            $this->seedTenantPermissions($tenant);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    private function seedPlatformPermissions(): void
    {
        $permissions = $this->permissions([
            'manage platform',
        ], 'super_admin');

        $role = Role::query()->firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'super_admin',
            'team_id' => null,
        ]);
        $role->syncPermissions($permissions);
    }

    private function seedTenantPermissions(Tenant $tenant): void
    {
        $permissions = $this->permissions([
            'view customers',
            'create customers',
            'update customers',
            'delete customers',
            'view leads',
            'create leads',
            'update leads',
            'delete leads',
            'view staff',
            'create staff',
            'update staff',
            'delete staff',
            'view roles',
            'create roles',
            'update roles',
            'delete roles',
            'view bookings',
            'create bookings',
            'update bookings',
            'delete bookings',
            'view invoices',
            'create invoices',
            'update invoices',
            'delete invoices',
            'view catalog',
            'create catalog',
            'update catalog',
            'delete catalog',
        ], 'tenant');
        $portalPermission = $this->permissions(['access portal'], 'customer');

        $owner = $this->role($tenant, 'Tenant Owner');
        $owner->syncPermissions($permissions);

        $sales = $this->role($tenant, 'Sales Agent');
        $sales->syncPermissions(Permission::query()
            ->where('guard_name', 'tenant')
            ->whereIn('name', [
                'view customers', 'create customers', 'update customers',
                'view leads', 'create leads', 'update leads',
                'view bookings', 'update bookings',
                'view invoices',
            ])->get());

        $operations = $this->role($tenant, 'Operations');
        $operations->syncPermissions(Permission::query()
            ->where('guard_name', 'tenant')
            ->whereIn('name', ['view bookings', 'update bookings', 'view invoices'])->get());

        $accountant = $this->role($tenant, 'Accountant');
        $accountant->syncPermissions(Permission::query()
            ->where('guard_name', 'tenant')
            ->whereIn('name', ['view invoices', 'create invoices', 'update invoices', 'delete invoices'])
            ->get());

        $staff = TenantUser::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('email', 'staff@example.com')
            ->first();
        $staff?->syncRoles([$owner]);

        $customerRole = $this->role($tenant, 'Customer', 'customer');
        $customerRole->syncPermissions($portalPermission);

        $customer = Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('email', 'customer@example.com')
            ->first();
        $customer?->syncRoles([$customerRole]);
    }

    private function role(Tenant $tenant, string $name, string $guard = 'tenant'): Role
    {
        return Role::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => $guard,
            'team_id' => $tenant->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Permission>
     */
    private function permissions(array $names, string $guard): Collection
    {
        return collect($names)
            ->map(fn (string $name): Permission => Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]));
    }
}
