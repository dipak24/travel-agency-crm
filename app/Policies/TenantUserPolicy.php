<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class TenantUserPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'view staff');
    }

    public function view(TenantUser $user, TenantUser $record): bool
    {
        return $this->sameTenant($user, $record);
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create staff');
    }

    public function update(TenantUser $user, TenantUser $record): Response
    {
        return $this->sameTenant($user, $record) && $this->hasPermission($user, 'update staff')
            ? Response::allow()
            : Response::deny('Only a tenant owner can manage staff in this tenant.');
    }

    public function delete(TenantUser $user, TenantUser $record): bool
    {
        return $this->sameTenant($user, $record) && $this->hasPermission($user, 'delete staff') && $user->id !== $record->id;
    }

    private function sameTenant(TenantUser $user, TenantUser $record): bool
    {
        return $user->tenant_id === $record->tenant_id;
    }

    private function hasPermission(TenantUser $user, string $permission): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo($permission);
    }
}
