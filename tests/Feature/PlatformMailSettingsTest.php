<?php

use App\Filament\Pages\MailSettings;
use App\Models\PlatformMailSetting;
use App\Models\SuperAdmin;
use App\Notifications\TestMailSettingNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('a super admin can save the platform-wide SMTP settings', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'super_admin')->test(MailSettings::class)
        ->set('data.enabled', true)
        ->set('data.from_name', 'Platform Notifications')
        ->set('data.from_address', 'noreply@platform.example')
        ->set('data.host', 'smtp.platform.example')
        ->set('data.port', 587)
        ->set('data.username', 'platform-user')
        ->set('data.password', 'platform-pass')
        ->set('data.encryption', 'tls')
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = PlatformMailSetting::query()->firstOrFail();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->from_address)->toBe('noreply@platform.example')
        ->and($settings->credentials['host'])->toBe('smtp.platform.example');
});

test('enabling the platform SMTP settings without required fields is rejected', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'super_admin')->test(MailSettings::class)
        ->set('data.enabled', true)
        ->set('data.from_name', '')
        ->set('data.from_address', '')
        ->set('data.port', '')
        ->call('save')
        ->assertHasFormErrors(['from_name', 'from_address', 'host', 'port', 'username', 'password']);

    expect(PlatformMailSetting::query()->exists())->toBeFalse();
});

test('saved platform SMTP settings are reloaded correctly on the next visit', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    PlatformMailSetting::query()->create([
        'enabled' => true,
        'from_name' => 'Platform Notifications',
        'from_address' => 'noreply@platform.example',
        'credentials' => [
            'host' => 'smtp.platform.example', 'port' => 587,
            'username' => 'platform-user', 'password' => 'platform-pass', 'encryption' => 'tls',
        ],
    ]);

    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'super_admin')->test(MailSettings::class)
        ->assertSet('data.enabled', true)
        ->assertSet('data.from_address', 'noreply@platform.example')
        ->assertSet('data.host', 'smtp.platform.example');
});

test('a platform staff member without the manage platform permission cannot access mail settings', function () {
    $this->seed();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $staff = SuperAdmin::factory()->create();
    $role = Role::create(['name' => 'Support', 'guard_name' => 'super_admin', 'team_id' => 0]);
    $staff->assignRole($role);

    $this->actingAs($staff, 'super_admin');

    expect(MailSettings::canAccess())->toBeFalse();
});

test('sending a test email notifies the logged-in super admin once platform settings are enabled', function () {
    Notification::fake();
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    PlatformMailSetting::query()->create([
        'enabled' => true,
        'from_name' => 'Platform Notifications',
        'from_address' => 'noreply@platform.example',
        'credentials' => [
            'host' => 'smtp.platform.example', 'port' => 587,
            'username' => 'platform-user', 'password' => 'platform-pass', 'encryption' => 'tls',
        ],
    ]);

    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'super_admin')->test(MailSettings::class)
        ->call('sendTestEmail');

    Notification::assertSentTo($admin, TestMailSettingNotification::class);
});
