---
paths:
  - 'app/Policies/**,database/seeders/**,app/Http/Middleware/**'
---

# Middleware

## Spatie teams mode requires a non-null team_id for every role assignment — super_admin guard uses sentinel team_id=0
config/permission.php has 'teams' => true, so model_has_roles.team_id / model_has_permissions.team_id are NOT NULL composite-primary-key columns. Assigning a role/permission with the registrar's team id left at null throws a Postgres not-null violation.

super_admins aren't tenant-scoped, so there's no real tenant id to use. They use a fixed sentinel team_id = 0 instead (see PermissionSeeder::seedPlatformPermissions, DatabaseSeeder's admin role assignment, and SuperAdminPolicy::hasPermission). App\Http\Middleware\ResolvePlatformTeam sets PermissionRegistrar::setPermissionsTeamId(0) for the duration of any authenticated super_admin request (registered on the admin Filament panel), mirroring how App\Support\TenantContext sets the real tenant id for the tenant/portal guards via ResolveTenant.

Any new code that calls ->assignRole()/->syncRoles()/->hasRole()/->hasPermissionTo() on a SuperAdmin outside an HTTP request (tinker, a console command, a queued job) must explicitly wrap it with setPermissionsTeamId(0) first (and reset to null after), since there's no middleware running to do it automatically.
