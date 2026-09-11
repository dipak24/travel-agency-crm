<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a super admin can use the admin panel forgot-password page', function () {
    $admin = SuperAdmin::factory()->create();

    Notification::fake();
    Filament::setCurrentPanel('admin');

    Livewire::test(RequestPasswordReset::class)
        ->set('data.email', $admin->email)
        ->call('request');

    Notification::assertSentTo($admin, FilamentResetPasswordNotification::class);
});

test('a tenant staff member can use the tenant panel forgot-password page', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create();

    Notification::fake();
    Filament::setCurrentPanel('tenant');

    Livewire::test(RequestPasswordReset::class)
        ->set('data.email', $staff->email)
        ->call('request');

    Notification::assertSentTo($staff, FilamentResetPasswordNotification::class);
});
