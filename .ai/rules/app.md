---
paths:
  - 'app/**'
---

# App

## Route every outbound notification through App\Services\Mail\TenantMailer, never call ->notify() directly
All outgoing email now supports per-tenant SMTP (App\Filament\Tenant\Pages\MailSettings, `tenant_mail_settings`) with a separate platform-wide fallback (App\Filament\Pages\MailSettings, `platform_mail_settings`, Super Admin only, gated by `manage platform`). App\Services\Mail\TenantMailer::send(?int $tenantId, $notifiable, $notification) is the only place that resolves which SMTP account to use — it checks the tenant's own enabled settings first, then the platform's, then falls through untouched to the app's .env default mailer.

Any new notification call site must go through `app(TenantMailer::class)->send($record->tenant_id, $notifiable, new SomeNotification(...))` instead of `$notifiable->notify(new SomeNotification(...))` directly, passing the record's own tenant_id (never the ambient TenantContext — mirrors the existing ResolvesTenantCredentials/payment-gateway convention), or a tenant email will silently ignore that tenant's configured SMTP account and use the platform/.env default instead.

Trap already hit once: TenantMailer restores mail.default/mail.from in a finally block after send() — when building similar "swap config for one call" logic, config() requires fully-qualified dotted keys (`'mail.default'`, not `'default'`); passing short keys silently sets an unrelated top-level config key and the swap never gets undone. This is safe today only because no notification in this app implements ShouldQueue (all send synchronously) — if one ever is queued, re-check that a reused queue-worker process can't leak one tenant's swapped mailer config into another tenant's job.
