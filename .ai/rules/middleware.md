---
paths:
  - 'app/Policies/**,database/seeders/**,app/Http/Middleware/**'
---

# Middleware

## Spatie teams mode requires a non-null team_id for every role assignment — super_admin guard uses sentinel team_id=0
config/permission.php has 'teams' => true, so model_has_roles.team_id / model_has_permissions.team_id are NOT NULL composite-primary-key columns. Assigning a role/permission with the registrar's team id left at null throws a Postgres not-null violation.

super_admins aren't tenant-scoped, so there's no real tenant id to use. They use a fixed sentinel team_id = 0 instead (see PermissionSeeder::seedPlatformPermissions, DatabaseSeeder's admin role assignment, and SuperAdminPolicy::hasPermission). App\Http\Middleware\ResolvePlatformTeam sets PermissionRegistrar::setPermissionsTeamId(0) for the duration of any authenticated super_admin request (registered on the admin Filament panel), mirroring how App\Support\TenantContext sets the real tenant id for the tenant/portal guards via ResolveTenant.

Any new code that calls ->assignRole()/->syncRoles()/->hasRole()/->hasPermissionTo() on a SuperAdmin outside an HTTP request (tinker, a console command, a queued job) must explicitly wrap it with setPermissionsTeamId(0) first (and reset to null after), since there's no middleware running to do it automatically.

## Never clear per-request context in a `finally` around `$next()` — use `terminate()` instead

Middleware registered as Filament persistent middleware (`->middleware([...], isPersistent: true)` / `->authMiddleware([...], isPersistent: true)`) gets replayed by Livewire on every AJAX component-update request (`Livewire\Mechanisms\PersistentMiddleware`), against a throwaway request piped through `Livewire\Drawer\Utils::applyMiddleware()`. That pipeline terminates immediately with a dummy Response — `$next($request)` returns right away, it does NOT chain into the real Livewire action.

So a middleware that does `try { return $next($request); } finally { /* clear state */ }` wipes that state immediately after setting it, on every single Livewire update — before the actual component method (`mount`, an action, `save`, etc.) runs. This was the root cause of gift-voucher purchases (and any tenant-owned model create triggered by a Livewire action) throwing "A tenant context is required..." from `BelongsToTenant`, even though the user was correctly authenticated and tenant-scoped: `ResolveTenant::handle()` set the tenant context, then its `finally` block cleared it again immediately during Livewire's replay, before `Invoice::create()` ran.

Fix: set state in `handle()` as before, but move the cleanup into a `terminate(Request $request, Response $response): void` method instead of a `finally` block. `terminate()` is only invoked once, by the real HTTP kernel after the actual request fully completes — Livewire's fake replay pipeline never calls it. See `App\Http\Middleware\ResolveTenant` for the fixed pattern, and the "keeps the tenant context set when replayed through an isolated pipeline" test in `tests/Feature/TenantIsolationTest.php` for a regression test that reproduces Livewire's replay via a raw `Illuminate\Pipeline\Pipeline`.
