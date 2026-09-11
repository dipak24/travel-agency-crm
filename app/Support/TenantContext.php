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

    /**
     * Run a callback with the tenant context set to $tenant, restoring the previous context afterwards.
     * For code paths (webhooks, console commands) that have no request-scoped ResolveTenant middleware to do this.
     */
    public function wrap(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous ? $this->set($previous) : $this->clear();
        }
    }
}
