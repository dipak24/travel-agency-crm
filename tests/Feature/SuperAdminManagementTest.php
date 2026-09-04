<?php

use App\Filament\Resources\SuperAdminResource\Pages\CreateSuperAdmin;
use App\Filament\Resources\SuperAdminResource\Pages\EditSuperAdmin;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Filament's fillForm() testing helper is broken on this project's exact
 * filament/forms 5.7.8 + livewire/livewire 4.4.3 combination: the state it
 * writes never survives to the following call(). Setting each field directly
 * through Livewire's own set() (confirmed working) exercises the same real
 * component/validation/save code path without depending on that helper.
 */
function fillSuperAdminForm(Testable $test, array $data): Testable
{
    foreach ($data as $key => $value) {
        $test->set("data.{$key}", $value);
    }

    return $test;
}

test('seeded platform admin can access the super admin resource pages', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->actingAs($admin, 'super_admin')->get('/admin/super-admins')->assertOk();
    $this->actingAs($admin, 'super_admin')->get('/admin/super-admins/create')->assertOk();
});

test('a platform admin can create a second platform-staff account', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    expect(Gate::forUser($admin)->allows('create', SuperAdmin::class))->toBeTrue();

    $second = SuperAdmin::factory()->create(['email' => 'support@example.com']);

    expect(Gate::forUser($admin)->allows('update', $second))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $second))->toBeTrue();
});

test('a platform admin without the Super Admin role is denied access', function () {
    $this->seed();

    $unprivileged = SuperAdmin::factory()->create();

    expect(Gate::forUser($unprivileged)->allows('viewAny', SuperAdmin::class))->toBeFalse()
        ->and(Gate::forUser($unprivileged)->allows('create', SuperAdmin::class))->toBeFalse();
});

test('a platform admin cannot delete their own account', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    expect(Gate::forUser($admin)->allows('delete', $admin))->toBeFalse();
});

test('creating a platform admin rejects a password missing complexity requirements', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $role = Role::query()->where('name', 'Super Admin')->where('guard_name', 'super_admin')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateSuperAdmin::class);

    fillSuperAdminForm($test, [
        'name' => 'Support Agent',
        'email' => 'support@example.com',
        'phone' => '+1 555 0101',
        'password' => 'password',
        'status' => 'active',
        'roles' => [$role->id],
    ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(SuperAdmin::query()->where('email', 'support@example.com')->exists())->toBeFalse();
});

test('creating a platform admin accepts a password meeting complexity requirements', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $role = Role::query()->where('name', 'Super Admin')->where('guard_name', 'super_admin')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateSuperAdmin::class);

    fillSuperAdminForm($test, [
        'name' => 'Support Agent',
        'email' => 'support@example.com',
        'phone' => '+1 555 0101',
        'password' => 'Sup3rSecret!',
        'status' => 'active',
        'roles' => [$role->id],
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = SuperAdmin::query()->where('email', 'support@example.com')->firstOrFail();
    expect(Hash::check('Sup3rSecret!', $created->password))->toBeTrue();
});

test('creating a platform admin requires selecting a role', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateSuperAdmin::class);

    fillSuperAdminForm($test, [
        'name' => 'Support Agent',
        'email' => 'support@example.com',
        'phone' => '+1 555 0101',
        'password' => 'Sup3rSecret!',
        'status' => 'active',
        'roles' => [],
    ])
        ->call('create')
        ->assertHasFormErrors(['roles' => 'required']);
});

test('creating a platform admin rejects an email already used by another platform admin', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $role = Role::query()->where('name', 'Super Admin')->where('guard_name', 'super_admin')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateSuperAdmin::class);

    fillSuperAdminForm($test, [
        'name' => 'Duplicate Admin',
        'email' => 'admin@example.com',
        'phone' => '+1 555 0102',
        'password' => 'Sup3rSecret!',
        'status' => 'active',
        'roles' => [$role->id],
    ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});

test('creating a platform admin rejects a phone number already used by another platform admin', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $role = Role::query()->where('name', 'Super Admin')->where('guard_name', 'super_admin')->firstOrFail();
    $existing = SuperAdmin::factory()->create(['phone' => '+1 555 0199']);

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateSuperAdmin::class);

    fillSuperAdminForm($test, [
        'name' => 'Duplicate Phone Admin',
        'email' => 'newadmin@example.com',
        'phone' => $existing->phone,
        'password' => 'Sup3rSecret!',
        'status' => 'active',
        'roles' => [$role->id],
    ])
        ->call('create')
        ->assertHasFormErrors(['phone' => 'unique']);
});

test('a platform admin editing their own account cannot change their own email, status, or role', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(EditSuperAdmin::class, ['record' => $admin->getKey()]);

    fillSuperAdminForm($test, [
        'email' => 'takeover@example.com',
        'status' => 'inactive',
        'roles' => [],
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    $admin->refresh();

    expect($admin->email)->toBe('admin@example.com')
        ->and($admin->status)->toBe('active')
        ->and($admin->hasRole('Super Admin'))->toBeTrue();
});

test('a platform admin can change another admin\'s email, status, and role', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $other = SuperAdmin::factory()->create(['status' => 'active']);

    $test = Livewire::actingAs($admin, 'super_admin')->test(EditSuperAdmin::class, ['record' => $other->getKey()]);

    fillSuperAdminForm($test, [
        'email' => 'reassigned@example.com',
        'status' => 'inactive',
        'roles' => [],
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    $other->refresh();

    expect($other->email)->toBe('reassigned@example.com')
        ->and($other->status)->toBe('inactive');
});
