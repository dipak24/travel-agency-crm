---
paths:
  - 'app/Services/TenantOnboarding.php,app/Policies/RolePolicy.php'
---

# Policies

## New tenant owner needs permissions synced at creation; "Tenant Owner" role is locked from tenant-portal edits
TenantOnboarding::create() must sync every guard=tenant Permission onto the "Tenant Owner" role right after Role::findOrCreate(). PermissionSeeder only wires up owner permissions for tenants that already existed at seed time — a tenant created later via the admin panel got a Tenant Owner role with zero permissions, so its owner logged into the tenant panel and saw an empty nav (every resource policy denied). This was a real reported bug, not hypothetical.

Separately, RolePolicy::isProtected() denies update/delete on the tenant-guard "Tenant Owner" role unconditionally (even for the owner themselves) — it's this tenant's portal "super admin" role and its permission set is only ever set by the platform admin at tenant creation, never editable from inside the tenant portal. Keep both fixes in sync if the owner-provisioning flow changes.
