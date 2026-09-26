---
paths:
  - 'database/migrations/**,app/Filament/Tenant/Resources/**,app/Filament/Resources/TenantResource*'
---

# Filament Resources

## tenant_users.email is globally unique by design
Decided 2026-09-05, kept 2026-09-26: staff originally logged in from one shared `/tenant/login`; since 2026-09-26 they log in on their agency's own subdomain (see `.ai/rules/app.md`), and lookups are scoped to that agency — but email stays globally unique as defence in depth, so one email can never mean two staff accounts. `tenant_users.email` is therefore unique **platform-wide** (`$table->unique('email')` in `create_tenant_users_table`, plus a separate `index('tenant_id')` since the old composite unique no longer covers tenant_id lookups) — not scoped per tenant like it used to be.

Why: `TenantScopedUserProvider` (bypasses tenant scoping for login lookups, since no tenant context exists yet at login) resolves accounts by email alone. If email were only unique per tenant, two different tenants could each have a `staff@example.com` row, and login would resolve ambiguously — a real cross-tenant account-confusion bug, not cosmetic.

`StaffResource` and admin `TenantResource`'s owner-email field both validate `Rule::unique('tenant_users', 'email')` with no `tenant_id` scoping — keep it that way.

Since 2026-09-26 `tenants.slug` IS the agency's subdomain — for the public API, the staff panel (`{slug}.{agency.domain}/tenant`) and the customer portal (`/portal`); see `.ai/rules/app.md`. Slugs must therefore be valid DNS labels and not in `config('agency.reserved_subdomains')` (validated in admin TenantResource). Renaming a slug breaks every link staff and customers already have.
