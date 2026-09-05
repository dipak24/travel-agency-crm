---
paths:
  - 'database/migrations/**,app/Filament/Tenant/Resources/**,app/Filament/Resources/TenantResource*'
---

# Filament Resources

## tenant_users.email is globally unique by design — shared /tenant/login, not per-tenant slug/subdomain
Decided 2026-09-05: tenant staff log in from a single shared `/tenant/login` (no slug/subdomain in the URL). `tenant_users.email` is therefore unique **platform-wide** (`$table->unique('email')` in `create_tenant_users_table`, plus a separate `index('tenant_id')` since the old composite unique no longer covers tenant_id lookups) — not scoped per tenant like it used to be.

Why: `TenantScopedUserProvider` (bypasses tenant scoping for login lookups, since no tenant context exists yet at login) resolves accounts by email alone. If email were only unique per tenant, two different tenants could each have a `staff@example.com` row, and login would resolve ambiguously — a real cross-tenant account-confusion bug, not cosmetic.

`StaffResource` and admin `TenantResource`'s owner-email field both validate `Rule::unique('tenant_users', 'email')` with no `tenant_id` scoping — keep it that way.

The `tenants.slug` column is intentionally NOT used for login/routing today — it's reserved for Phase 8 (public site tenant resolution by subdomain or `?tenant=slug`). Don't wire slug into staff login without a deliberate follow-up decision — subdomain-per-tenant and slug-in-URL-login were both explicitly considered and declined for now in favor of the simpler shared login.
