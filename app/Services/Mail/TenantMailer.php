<?php

namespace App\Services\Mail;

use App\Models\PlatformMailSetting;
use App\Models\TenantMailSetting;
use Illuminate\Notifications\Notification;

/**
 * Routes an outbound notification through the right SMTP account: the notifiable's own tenant
 * settings when that tenant has enabled its own (App\Filament\Tenant\Pages\MailSettings), falling
 * back to the platform-wide settings a Super Admin configures (App\Filament\Pages\MailSettings),
 * and finally to whatever this app's own .env-configured default mailer is if neither exists.
 * The two settings tables are entirely separate storage — this is only a sensible default while a
 * tenant hasn't set up their own, not a merge of the two.
 *
 * Every notification in this app sends synchronously (none implement ShouldQueue), so swapping
 * mail.default/mail.from for the duration of one notify() call and restoring it in a finally block
 * is safe — it never leaks into another tenant's request or a reused queue-worker process.
 */
class TenantMailer
{
    public function send(?int $tenantId, object $notifiable, Notification $notification): void
    {
        $original = ['mail.default' => config('mail.default'), 'mail.from' => config('mail.from')];
        $configured = $this->configureMailerFor($tenantId);

        try {
            $notifiable->notify($notification);
        } finally {
            if ($configured) {
                config($original);
            }
        }
    }

    private function configureMailerFor(?int $tenantId): bool
    {
        $settings = $tenantId ? $this->tenantSettings($tenantId) : null;
        $settings ??= $this->platformSettings();

        if (! $settings) {
            return false;
        }

        $mailerName = $settings instanceof TenantMailSetting
            ? "tenant_dynamic_{$settings->tenant_id}"
            : 'platform_dynamic';

        $credentials = $settings->credentials ?? [];

        config([
            "mail.mailers.{$mailerName}" => [
                'transport' => 'smtp',
                'host' => $credentials['host'] ?? null,
                'port' => $credentials['port'] ?? null,
                'username' => $credentials['username'] ?? null,
                'password' => $credentials['password'] ?? null,
                'encryption' => $credentials['encryption'] ?: null,
            ],
            'mail.default' => $mailerName,
            'mail.from' => [
                'address' => $settings->from_address,
                'name' => $settings->from_name,
            ],
        ]);

        return true;
    }

    private function tenantSettings(int $tenantId): ?TenantMailSetting
    {
        return TenantMailSetting::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->first();
    }

    private function platformSettings(): ?PlatformMailSetting
    {
        return PlatformMailSetting::query()->where('enabled', true)->first();
    }
}
