<?php

namespace App\Services\Auth;

use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\AgencySubdomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lets a Super Admin sign in to an agency's staff panel as one of its staff (support/debugging).
 *
 * Staff sessions live on the agency's own subdomain, so the admin panel can't just log the user in:
 * it issues a single-use token (cache, 60 seconds) behind a temporary signed URL on the agency
 * subdomain, which logs the staff member in there. The impersonating admin is kept in the session
 * so the staff panel shows a banner with "End impersonation". Start and end are both written to
 * the audit log, caused by the Super Admin and performed on the staff account.
 */
class TenantImpersonation
{
    public const SESSION_KEY = 'impersonator';

    public const TOKEN_TTL_SECONDS = 60;

    /**
     * @throws InvalidArgumentException when the staff account can't be signed in to.
     */
    public function start(SuperAdmin $admin, TenantUser $staff): string
    {
        $tenant = Tenant::query()->find($staff->tenant_id);

        if ($tenant === null || $tenant->status === 'suspended' || $staff->status !== 'active') {
            throw new InvalidArgumentException('Only active staff of an active agency can be impersonated.');
        }

        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'super_admin_id' => $admin->getKey(),
            'tenant_user_id' => $staff->getKey(),
        ], self::TOKEN_TTL_SECONDS);

        return AgencySubdomain::within($tenant, fn (): string => URL::temporarySignedRoute(
            'impersonation.enter',
            now()->addSeconds(self::TOKEN_TTL_SECONDS),
            ['token' => $token],
        ));
    }

    /**
     * Redeems a token on the agency subdomain. Returns the signed-in staff member, or null when
     * the token is unknown, used, expired, or belongs to a different agency.
     */
    public function enter(Request $request, string $token, Tenant $agency): ?TenantUser
    {
        $payload = Cache::pull($this->cacheKey($token));

        if (! is_array($payload)) {
            return null;
        }

        $admin = SuperAdmin::query()->find($payload['super_admin_id']);
        $staff = TenantUser::query()->withoutGlobalScopes()->find($payload['tenant_user_id']);

        if ($admin === null || $staff === null || $staff->tenant_id !== $agency->getKey() || $staff->status !== 'active') {
            return null;
        }

        activity()
            ->causedBy($admin)
            ->performedOn($staff)
            ->event('impersonation_started')
            ->withProperties(['staff_email' => $staff->email])
            ->log("Platform admin {$admin->name} started impersonating {$staff->name}");

        Auth::guard('tenant')->login($staff);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['id' => $admin->getKey(), 'name' => $admin->name]);

        return $staff;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public function impersonator(Request $request): ?array
    {
        $impersonator = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_array($impersonator) ? $impersonator : null;
    }

    /**
     * Ends the impersonated session. Returns false when the session wasn't an impersonation.
     */
    public function leave(Request $request): bool
    {
        $impersonator = $this->impersonator($request);
        $staff = Auth::guard('tenant')->user();

        if ($impersonator === null) {
            return false;
        }

        $admin = SuperAdmin::query()->find($impersonator['id']);

        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($staff instanceof TenantUser) {
            activity()
                ->causedBy($admin)
                ->performedOn($staff)
                ->event('impersonation_ended')
                ->withProperties(['staff_email' => $staff->email])
                ->log("Platform admin {$impersonator['name']} stopped impersonating {$staff->name}");
        }

        return true;
    }

    private function cacheKey(string $token): string
    {
        return 'impersonation:'.hash('sha256', $token);
    }
}
