<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spatie's teams mode requires a non-null team_id on every role/permission
 * assignment pivot row. Super admins aren't scoped to a tenant, so they use
 * a fixed sentinel team id (0) instead of a real tenant id.
 */
class ResolvePlatformTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth('super_admin')->user() instanceof SuperAdmin) {
            app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        }

        try {
            return $next($request);
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }
}
