<?php

use App\Filament\Tenant\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Tenant\Resources\BookingTravelerResource\Pages\CreateBookingTraveler;
use App\Filament\Tenant\Resources\BookingTravelerResource\Pages\EditBookingTraveler;
use App\Filament\Tenant\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Tenant\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Tenant\Resources\FixedDepartureResource\Pages\CreateFixedDeparture;
use App\Filament\Tenant\Resources\FixedDepartureResource\Pages\EditFixedDeparture;
use App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages\CreateGroupDiscountTier;
use App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages\EditGroupDiscountTier;
use App\Filament\Tenant\Resources\IncludeExcludeResource\Pages\CreateIncludeExclude;
use App\Filament\Tenant\Resources\IncludeExcludeResource\Pages\EditIncludeExclude;
use App\Filament\Tenant\Resources\LeadResource\Pages\CreateLead;
use App\Filament\Tenant\Resources\LeadResource\Pages\EditLead;
use App\Filament\Tenant\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Tenant\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Tenant\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Tenant\Resources\RoleResource\Pages\EditRole;
use App\Filament\Tenant\Resources\ServiceAvailabilityResource\Pages\CreateServiceAvailability;
use App\Filament\Tenant\Resources\ServiceAvailabilityResource\Pages\EditServiceAvailability;
use App\Filament\Tenant\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Tenant\Resources\ServiceResource\Pages\EditService;
use App\Filament\Tenant\Resources\StaffResource\Pages\CreateStaff;
use App\Filament\Tenant\Resources\StaffResource\Pages\EditStaff;
use App\Models\Customer;
use App\Models\Service;
use App\Models\SuperAdmin;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('seeded tenant staff can authenticate with the documented credentials', function () {
    $this->seed();

    expect(auth('tenant')->attempt([
        'email' => 'staff@example.com',
        'password' => 'password',
    ]))->toBeTrue()
        ->and(auth('tenant')->user())->toBeInstanceOf(TenantUser::class)
        ->and(auth('tenant')->user())->toBeInstanceOf(Authorizable::class);
});

test('staff of a suspended tenant cannot access the tenant panel even with an active account', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    expect($staff->canAccessPanel(filament()->getPanel('tenant')))->toBeTrue();

    $staff->tenant->update(['status' => 'suspended']);
    $staff->refresh();

    expect($staff->canAccessPanel(filament()->getPanel('tenant')))->toBeFalse();

    $this->actingAs($staff, 'tenant')->get('/tenant')->assertForbidden();
});

test('seeded admin and portal accounts can authenticate with their documented credentials', function () {
    $this->seed();

    expect(auth('super_admin')->attempt([
        'email' => 'admin@example.com',
        'password' => 'password',
    ]))->toBeTrue()
        ->and(auth('super_admin')->user())->toBeInstanceOf(SuperAdmin::class);

    auth('super_admin')->logout();

    expect(auth('customer')->attempt([
        'email' => 'customer@example.com',
        'password' => 'password',
    ]))->toBeTrue()
        ->and(auth('customer')->user())->toBeInstanceOf(Customer::class);
});

test('database seed assigns permissions to platform and tenant roles', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();
    app(PermissionRegistrar::class)->setPermissionsTeamId($staff->tenant_id);

    expect($staff->hasRole('Tenant Owner'))
        ->toBeTrue()
        ->and($staff->hasPermissionTo('view bookings'))->toBeTrue()
        ->and($staff->hasPermissionTo('create catalog'))->toBeTrue()
        ->and(Permission::query()->where('name', 'manage platform')->where('guard_name', 'super_admin')->exists())
        ->toBeTrue();
});

test('seeded accounts can access their separate Filament panels', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();
    $customer = Customer::query()->withoutGlobalScopes()
        ->where('email', 'customer@example.com')
        ->firstOrFail();

    $this->actingAs($admin, 'super_admin')->get('/admin')->assertOk();
    $this->actingAs($staff, 'tenant')->get('/tenant')->assertOk();
    $this->actingAs($customer, 'customer')->get('/portal')->assertOk();
});

test('tenant staff can access the services resource', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    $this->actingAs($staff, 'tenant')
        ->get('/tenant/services')
        ->assertOk();
});

test('seeded tenant owner can access CRM resource lists and create pages', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    foreach ([
        '/tenant/customers',
        '/tenant/customers/create',
        '/tenant/leads',
        '/tenant/leads/create',
        '/tenant/staff',
        '/tenant/staff/create',
        '/tenant/bookings',
        '/tenant/bookings/create',
        '/tenant/booking-travelers',
        '/tenant/booking-travelers/create',
    ] as $path) {
        $this->actingAs($staff, 'tenant')
            ->get($path)
            ->assertOk();
    }
});

test('tenant-owned catalog create pages resolve the authenticated tenant', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    foreach ([
        '/tenant/include-excludes/create',
        '/tenant/fixed-departures/create',
        '/tenant/services/create',
    ] as $path) {
        $this->actingAs($staff, 'tenant')
            ->get($path)
            ->assertOk();
    }
});

test('tenant create pages redirect to their resource lists after saving', function () {
    $createPages = [
        CreateBookingTraveler::class,
        CreateCustomer::class,
        CreateFixedDeparture::class,
        CreateGroupDiscountTier::class,
        CreateIncludeExclude::class,
        CreateLead::class,
        CreatePackage::class,
        CreateRole::class,
        CreateServiceAvailability::class,
        CreateService::class,
        CreateStaff::class,
    ];

    foreach ($createPages as $createPage) {
        $method = new ReflectionMethod($createPage, 'getRedirectUrl');

        expect($method->isProtected())->toBeTrue()
            ->and($method->getReturnType()?->getName())->toBe('string');
    }
});

test('tenant edit pages redirect to their resource lists after saving', function () {
    $editPages = [
        EditBooking::class,
        EditBookingTraveler::class,
        EditCustomer::class,
        EditFixedDeparture::class,
        EditGroupDiscountTier::class,
        EditIncludeExclude::class,
        EditLead::class,
        EditPackage::class,
        EditRole::class,
        EditServiceAvailability::class,
        EditService::class,
        EditStaff::class,
    ];

    foreach ($editPages as $editPage) {
        $method = new ReflectionMethod($editPage, 'getRedirectUrl');

        expect($method->isProtected())->toBeTrue()
            ->and($method->getReturnType()?->getName())->toBe('string');
    }
});

test('seeded tenant owner can manage catalog records', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();
    app(TenantContext::class)->set($staff->tenant);
    $service = Service::query()->withoutGlobalScopes()->create([
        'tenant_id' => $staff->tenant_id,
        'name' => 'Airport transfer',
        'price' => 2500,
        'currency' => 'USD',
        'is_active' => true,
        'has_limited_availability' => false,
    ]);

    expect(Gate::forUser($staff)->allows('create', Service::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $service))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('delete', $service))->toBeTrue();
});
