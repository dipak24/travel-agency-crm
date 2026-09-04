<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class LeadPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser $user, Lead $lead): bool
    {
        return $this->sameTenant($user, $lead);
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create leads');
    }

    public function update(TenantUser $user, Lead $lead): Response
    {
        return $this->sameTenant($user, $lead, 'update leads')
            ? Response::allow()
            : Response::deny('Lead belongs to another tenant.');
    }

    public function delete(TenantUser $user, Lead $lead): bool
    {
        return $this->sameTenant($user, $lead, 'delete leads');
    }

    private function canManage(TenantUser $user): bool
    {
        app(TenantContext::class)->set($user->tenant);

        return $user->hasPermissionTo('view leads');
    }

    private function sameTenant(TenantUser $user, Lead $lead, string $permission = 'view leads'): bool
    {
        return $user->tenant_id === $lead->tenant_id && $this->hasPermission($user, $permission);
    }

    private function hasPermission(TenantUser $user, string $permission): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'tenant')->exists()) {
            return $user->hasAnyRole(['Tenant Owner', 'Sales Agent']);
        }

        return $user->hasPermissionTo($permission);
    }
}
