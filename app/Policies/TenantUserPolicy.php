<?php

namespace App\Policies;

use App\Models\TenantUser;
use Illuminate\Auth\Access\Response;

class TenantUserPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->isOwner($user);
    }

    public function view(TenantUser $user, TenantUser $record): bool
    {
        return $this->sameTenant($user, $record);
    }

    public function create(TenantUser $user): bool
    {
        return $this->isOwner($user);
    }

    public function update(TenantUser $user, TenantUser $record): Response
    {
        return $this->sameTenant($user, $record) && $this->isOwner($user)
            ? Response::allow()
            : Response::deny('Only a tenant owner can manage staff in this tenant.');
    }

    public function delete(TenantUser $user, TenantUser $record): bool
    {
        return $this->sameTenant($user, $record) && $this->isOwner($user) && $user->id !== $record->id;
    }

    private function sameTenant(TenantUser $user, TenantUser $record): bool
    {
        return $user->tenant_id === $record->tenant_id;
    }

    private function isOwner(TenantUser $user): bool
    {
        return $user->hasRole('Tenant Owner');
    }
}
