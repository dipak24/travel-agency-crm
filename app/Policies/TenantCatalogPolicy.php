<?php

namespace App\Policies;

use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Model;

class TenantCatalogPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $user->hasPermissionTo('view catalog');
    }

    public function view(TenantUser $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $user->hasPermissionTo('view catalog');
    }

    public function create(TenantUser $user): bool
    {
        return $user->hasPermissionTo('create catalog');
    }

    public function update(TenantUser $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $user->hasPermissionTo('update catalog');
    }

    public function delete(TenantUser $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $user->hasPermissionTo('delete catalog');
    }

    private function sameTenant(TenantUser $user, Model $record): bool
    {
        return (int) $record->getAttribute('tenant_id') === $user->tenant_id;
    }
}
