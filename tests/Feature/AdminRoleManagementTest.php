<?php

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\SuperAdmin;
use App\Policies\RolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * fillForm() is broken on this project's exact filament/forms + livewire
 * combo — see .ai/rules/feature.md. Set each field directly instead.
 */
function fillAdminRoleForm(Testable $test, array $data): Testable
{
    foreach ($data as $key => $value) {
        $test->set("data.{$key}", $value);
    }

    return $test;
}

test('seeded platform admin can access the platform role resource pages', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->actingAs($admin, 'super_admin')->get('/admin/roles')->assertOk();
    $this->actingAs($admin, 'super_admin')->get('/admin/roles/create')->assertOk();
});

test('a platform admin can create a platform role scoped to the super_admin guard', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $permission = Permission::query()->where('name', 'view roles')->where('guard_name', 'super_admin')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateRole::class);

    fillAdminRoleForm($test, [
        'name' => 'Support',
        'permissions' => [$permission->id],
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'Support')->firstOrFail();

    expect($role->guard_name)->toBe('super_admin')
        ->and($role->team_id)->toBe(0)
        ->and($role->hasPermissionTo($permission))->toBeTrue();
});

test('a platform admin can edit a platform role\'s permissions', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $role = Role::query()->create(['name' => 'Support', 'guard_name' => 'super_admin', 'team_id' => 0]);
    $permission = Permission::query()->where('name', 'update roles')->where('guard_name', 'super_admin')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(EditRole::class, ['record' => $role->getKey()]);

    fillAdminRoleForm($test, ['permissions' => [$permission->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    expect($role->fresh()->hasPermissionTo($permission))->toBeTrue();
});

test('platform roles are scoped to the super_admin guard and excluded from tenant role listings', function () {
    $this->seed();

    $platformRole = Role::query()->create(['name' => 'Billing', 'guard_name' => 'super_admin', 'team_id' => 0]);

    expect(RoleResource::getEloquentQuery()->whereKey($platformRole->id)->exists())->toBeTrue();

    $tenantRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->firstOrFail();

    expect(RoleResource::getEloquentQuery()->whereKey($tenantRole->id)->exists())->toBeFalse();
});

test('platform role policy denies a tenant user from managing platform roles', function () {
    $this->seed();

    $tenantOwnerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->firstOrFail();
    $platformRole = Role::query()->create(['name' => 'Billing', 'guard_name' => 'super_admin', 'team_id' => 0]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $unprivileged = SuperAdmin::factory()->create();

    expect((new RolePolicy)->view($unprivileged, $platformRole))->toBeTrue()
        ->and((new RolePolicy)->update($unprivileged, $tenantOwnerRole)->denied())->toBeTrue()
        ->and((new RolePolicy)->viewAny($unprivileged))->toBeFalse();
});
