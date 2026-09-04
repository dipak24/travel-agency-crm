<?php

namespace App\Policies;

use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

class TenantCatalogPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'view catalog');
    }

    public function view(TenantUser $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $this->hasPermission($user, 'view catalog');
    }

    public function create(TenantUser $user): bool
    {
        return $this->hasPermission($user, 'create catalog');
    }

    public function update(TenantUser $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $this->hasPermission($user, 'update catalog');
    }

    public function delete(TenantUser $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $this->hasPermission($user, 'delete catalog');
    }

    private function sameTenant(TenantUser $user, Model $record): bool
    {
        $this->setTenantContext($user);

        return (int) $record->getAttribute('tenant_id') === $user->tenant_id;
    }

    private function hasPermission(TenantUser $user, string $permission): bool
    {
        $this->setTenantContext($user);

        return $user->hasPermissionTo($permission);
    }

    private function setTenantContext(TenantUser $user): void
    {
        app(TenantContext::class)->set($user->tenant);
    }
}
