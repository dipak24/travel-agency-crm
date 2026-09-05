---
paths:
  - 'app/Models/**,app/Filament/Resources/**'
---

# Resources

## Tenant-owned models (BelongsToTenant) are invisible from the admin panel without withoutGlobalScopes()
Any model using the `BelongsToTenant` trait (TenantUser, Customer, TenantSubscription, etc.) has a global `TenantScope` that filters `where tenant_id = TenantContext::id() ?? 0`. The super_admin-guard admin panel never sets a `TenantContext` (only `ResolvePlatformTeam` runs, which sets the Spatie permission team id, not `TenantContext`), so any relation or query touching a tenant-owned model from an admin Filament Resource silently returns nothing (`tenant_id = 0` matches no real tenant).

Fix: add `->withoutGlobalScopes()` on the relation/query when it's meant to be read cross-tenant from the admin panel (see `Tenant::activeSubscription()`). To *create* a tenant-owned row from the admin panel (e.g. reassigning a `TenantSubscription`), you must wrap the create in `app(TenantContext::class)->set($tenant); ...; ->clear();` first — `BelongsToTenant`'s `creating` hook throws a `LogicException` otherwise, and force-overwrites `tenant_id` from the context even if you pass one explicitly.

Existing tests/seeders already work around this with `->withoutGlobalScopes()->where('tenant_id', ...)` — see `TenantOnboardingTest`, `PermissionSeeder`.
