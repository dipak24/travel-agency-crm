<?php

namespace App\Support;

use App\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function clear(): void
    {
        $this->tenant = null;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
