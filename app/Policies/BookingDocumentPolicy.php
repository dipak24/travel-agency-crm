<?php

namespace App\Policies;

use App\Models\BookingDocument;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Permission;

class BookingDocumentPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser $user, BookingDocument $document): bool
    {
        return $this->sameTenant($user, $document);
    }

    public function create(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function update(TenantUser $user, BookingDocument $document): Response
    {
        return $this->sameTenant($user, $document)
            ? Response::allow()
            : Response::deny('Document belongs to another tenant.');
    }

    public function delete(TenantUser $user, BookingDocument $document): Response
    {
        return $this->sameTenant($user, $document)
            ? Response::allow()
            : Response::deny('Document belongs to another tenant.');
    }

    private function canManage(TenantUser $user): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'view bookings')->where('guard_name', 'tenant')->exists()) {
            return $user->hasAnyRole(['Tenant Owner', 'Sales Agent', 'Operations']);
        }

        return $user->hasPermissionTo('view bookings');
    }

    private function sameTenant(TenantUser $user, BookingDocument $document): bool
    {
        return $document->tenant_id === $user->tenant_id && $this->canManage($user);
    }
}
