<?php

use App\Models\PlatformMailSetting;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Notifications\TestMailSettingNotification;
use App\Services\Mail\TenantMailer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A minimal stand-in for a Notifiable model — TenantMailer::send() only requires a duck-typed
 * notify() method, so this records what mail config was actually live at the moment notify() was
 * called, without going through the real Notification/Mail pipeline at all.
 */
function tenantMailerProbe(): object
{
    return new class
    {
        public array $seen = [];

        public function notify($notification): void
        {
            $this->seen = [
                'default' => config('mail.default'),
                'host' => config('mail.mailers.'.config('mail.default').'.host'),
                'from' => config('mail.from'),
            ];
        }
    };
}

test('falls back to the app default mailer when neither tenant nor platform settings exist', function () {
    $originalDefault = config('mail.default');
    $originalFrom = config('mail.from');

    $probe = tenantMailerProbe();
    app(TenantMailer::class)->send(null, $probe, new TestMailSettingNotification);

    expect($probe->seen['default'])->toBe($originalDefault)
        ->and($probe->seen['from'])->toBe($originalFrom)
        ->and(config('mail.default'))->toBe($originalDefault)
        ->and(config('mail.from'))->toBe($originalFrom);
});

test('uses the tenant\'s own SMTP settings when the tenant has enabled them', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);

    TenantMailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
        'from_name' => 'Northwind Support',
        'from_address' => 'support@northwind.example',
        'credentials' => [
            'host' => 'smtp.northwind.example', 'port' => 2525,
            'username' => 'nw-user', 'password' => 'nw-pass', 'encryption' => 'tls',
        ],
    ]);

    $originalDefault = config('mail.default');
    $probe = tenantMailerProbe();

    app(TenantMailer::class)->send($tenant->id, $probe, new TestMailSettingNotification);

    expect($probe->seen['host'])->toBe('smtp.northwind.example')
        ->and($probe->seen['from']['address'])->toBe('support@northwind.example')
        ->and($probe->seen['default'])->not->toBe($originalDefault)
        ->and(config('mail.default'))->toBe($originalDefault);
});

test('falls back to the platform settings when the tenant has not enabled its own', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);

    PlatformMailSetting::query()->create([
        'enabled' => true,
        'from_name' => 'Platform Notifications',
        'from_address' => 'noreply@platform.example',
        'credentials' => [
            'host' => 'smtp.platform.example', 'port' => 2525,
            'username' => 'platform-user', 'password' => 'platform-pass', 'encryption' => 'tls',
        ],
    ]);

    $probe = tenantMailerProbe();
    app(TenantMailer::class)->send($tenant->id, $probe, new TestMailSettingNotification);

    expect($probe->seen['host'])->toBe('smtp.platform.example')
        ->and($probe->seen['from']['address'])->toBe('noreply@platform.example');
});

test('a tenant\'s own settings take priority over the platform settings', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);

    TenantMailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
        'from_name' => 'Northwind Support',
        'from_address' => 'support@northwind.example',
        'credentials' => ['host' => 'smtp.northwind.example', 'port' => 2525, 'username' => 'nw', 'password' => 'nw', 'encryption' => 'tls'],
    ]);

    PlatformMailSetting::query()->create([
        'enabled' => true,
        'from_name' => 'Platform Notifications',
        'from_address' => 'noreply@platform.example',
        'credentials' => ['host' => 'smtp.platform.example', 'port' => 2525, 'username' => 'p', 'password' => 'p', 'encryption' => 'tls'],
    ]);

    $probe = tenantMailerProbe();
    app(TenantMailer::class)->send($tenant->id, $probe, new TestMailSettingNotification);

    expect($probe->seen['host'])->toBe('smtp.northwind.example');
});

test('a disabled tenant setting is ignored in favour of the platform fallback', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);

    TenantMailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'enabled' => false,
        'from_name' => 'Northwind Support',
        'from_address' => 'support@northwind.example',
        'credentials' => ['host' => 'smtp.northwind.example', 'port' => 2525, 'username' => 'nw', 'password' => 'nw', 'encryption' => 'tls'],
    ]);

    PlatformMailSetting::query()->create([
        'enabled' => true,
        'from_name' => 'Platform Notifications',
        'from_address' => 'noreply@platform.example',
        'credentials' => ['host' => 'smtp.platform.example', 'port' => 2525, 'username' => 'p', 'password' => 'p', 'encryption' => 'tls'],
    ]);

    $probe = tenantMailerProbe();
    app(TenantMailer::class)->send($tenant->id, $probe, new TestMailSettingNotification);

    expect($probe->seen['host'])->toBe('smtp.platform.example');
});

test('two tenants sending in the same process each get their own mailer with no leakage', function () {
    $tenantA = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenantA);
    TenantMailSetting::query()->create([
        'tenant_id' => $tenantA->id,
        'enabled' => true,
        'from_name' => 'A',
        'from_address' => 'a@example.com',
        'credentials' => ['host' => 'smtp.a.example', 'port' => 2525, 'username' => 'a', 'password' => 'a', 'encryption' => 'tls'],
    ]);

    $tenantB = Tenant::query()->create(['name' => 'Southbound Travel', 'slug' => 'southbound-travel']);
    app(TenantContext::class)->set($tenantB);
    TenantMailSetting::query()->create([
        'tenant_id' => $tenantB->id,
        'enabled' => true,
        'from_name' => 'B',
        'from_address' => 'b@example.com',
        'credentials' => ['host' => 'smtp.b.example', 'port' => 2525, 'username' => 'b', 'password' => 'b', 'encryption' => 'tls'],
    ]);

    $probeA = tenantMailerProbe();
    $probeB = tenantMailerProbe();
    $mailer = app(TenantMailer::class);

    $mailer->send($tenantA->id, $probeA, new TestMailSettingNotification);
    $mailer->send($tenantB->id, $probeB, new TestMailSettingNotification);

    expect($probeA->seen['host'])->toBe('smtp.a.example')
        ->and($probeB->seen['host'])->toBe('smtp.b.example')
        ->and($probeA->seen['default'])->not->toBe($probeB->seen['default']);
});

test('a failed send is caught, logged, and returns false, and the original mail config is still restored', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    TenantMailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
        'from_name' => 'Northwind Support',
        'from_address' => 'support@northwind.example',
        'credentials' => ['host' => 'smtp.northwind.example', 'port' => 2525, 'username' => 'nw', 'password' => 'nw', 'encryption' => 'tls'],
    ]);

    $originalDefault = config('mail.default');

    $throwingNotifiable = new class
    {
        public function notify($notification): void
        {
            throw new RuntimeException('boom');
        }
    };

    $sent = app(TenantMailer::class)->send($tenant->id, $throwingNotifiable, new TestMailSettingNotification);

    expect($sent)->toBeFalse()
        ->and(config('mail.default'))->toBe($originalDefault);
});
