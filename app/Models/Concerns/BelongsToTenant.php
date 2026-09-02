<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw new LogicException('A tenant context is required to create tenant-owned records.');
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }
}
