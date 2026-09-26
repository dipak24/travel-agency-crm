<?php

namespace App\Auth;

use App\Support\AgencySubdomain;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Looks accounts up without the tenant global scope (no tenant context exists yet at login). On an
 * agency subdomain, staff and customer lookups — login, session restore, "Forgot password" and
 * password reset — are limited to that agency: customer emails are only unique per agency, and a
 * staff member of one agency must never be able to sign in on another agency's address.
 */
class TenantScopedUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null): Builder
    {
        $query = ($model ?? $this->createModel())->newQueryWithoutScopes();
        $agency = app(AgencySubdomain::class)->get();

        if ($agency !== null) {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), $agency->getKey());
        }

        return $query;
    }
}
