---
paths:
  - 'app/**'
---

# App

## Route every outbound notification through App\Services\Mail\TenantMailer, never call ->notify() directly
All outgoing email now supports per-tenant SMTP (App\Filament\Tenant\Pages\MailSettings, `tenant_mail_settings`) with a separate platform-wide fallback (App\Filament\Pages\MailSettings, `platform_mail_settings`, Super Admin only, gated by `manage platform`). App\Services\Mail\TenantMailer::send(?int $tenantId, $notifiable, $notification) is the only place that resolves which SMTP account to use — it checks the tenant's own enabled settings first, then the platform's, then falls through untouched to the app's .env default mailer.

Any new notification call site must go through `app(TenantMailer::class)->send($record->tenant_id, $notifiable, new SomeNotification(...))` instead of `$notifiable->notify(new SomeNotification(...))` directly, passing the record's own tenant_id (never the ambient TenantContext — mirrors the existing ResolvesTenantCredentials/payment-gateway convention), or a tenant email will silently ignore that tenant's configured SMTP account and use the platform/.env default instead.

Trap already hit once: TenantMailer restores mail.default/mail.from in a finally block after send() — when building similar "swap config for one call" logic, config() requires fully-qualified dotted keys (`'mail.default'`, not `'default'`); passing short keys silently sets an unrelated top-level config key and the swap never gets undone. This is safe today only because no notification in this app implements ShouldQueue (all send synchronously) — if one ever is queued, re-check that a reused queue-worker process can't leak one tenant's swapped mailer config into another tenant's job.

## Staff panel and customer portal live on the agency's subdomain — build their links with AgencySubdomain
Decided 2026-09-26: each agency is served at {tenant slug}.{config('agency.domain')} — staff panel at /tenant, customer portal at /portal — branded with the agency's name/logo/favicon/colours (App\Filament\AgencyBranding, set by the Super Admin on the tenant), login pages included. agency.domain = APP_PUBLIC_DOMAIN, else APP_URL's host (locally demo-travel.localhost:8000). The Super Admin panel stays on the platform domain only (EnsurePlatformDomain 404s it on agency subdomains).

ResolveAgencySubdomain (persistent middleware on the tenant and portal panels, also on /portal/pay and /portal/email-change) sets App\Support\AgencySubdomain + TenantContext, and answers the bare domain/unknown slugs with the same 404 "use your agency's link" page (no agency enumeration). TenantScopedUserProvider scopes staff AND customer lookups (login, session restore, forgot/reset password) to that agency, and TenantUser/Customer::canAccessPanel reject the other agencies' users.

Any staff- or customer-facing URL built outside a request on that subdomain (emails, queued notifications, reminders, links sent from the admin panel) MUST use AgencySubdomain::url($tenant) or AgencySubdomain::within($tenant, fn () => route(...)) — plain route()/url() points at the platform host (the helpful 404), and signed URLs sign the host. Never set SESSION_DOMAIN to a parent domain: per-subdomain cookies are what isolate agency sessions. Branding uploads must never accept SVG (script in the public disk).

Tests: `$this->actingAsStaff($user)` (tests/TestCase.php) roots relative requests at the staff member's subdomain; use portalUrl()/portalRoute() (tests/Pest.php) for other agency-host requests, and an absolute config('app.url') URL for platform-domain requests made after one.
