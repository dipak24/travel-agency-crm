<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Auth\Access\Response;

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
        return $this->canManage($user);
    }

    public function update(TenantUser $user, Lead $lead): Response
    {
        return $this->sameTenant($user, $lead)
            ? Response::allow()
            : Response::deny('Lead belongs to another tenant.');
    }

    public function delete(TenantUser $user, Lead $lead): bool
    {
        return $this->sameTenant($user, $lead) && $user->hasRole('Tenant Owner');
    }

    private function canManage(TenantUser $user): bool
    {
        return $user->hasAnyRole(['Tenant Owner', 'Sales Agent']);
    }

    private function sameTenant(TenantUser $user, Lead $lead): bool
    {
        return $user->tenant_id === $lead->tenant_id && $this->canManage($user);
    }
}
