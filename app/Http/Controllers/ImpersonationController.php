<?php

namespace App\Http\Controllers;

use App\Services\Auth\TenantImpersonation;
use App\Support\AgencySubdomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agency-subdomain half of Super Admin impersonation (see TenantImpersonation): redeems the
 * signed hand-off link from the admin panel, and ends the impersonated session from the banner.
 */
class ImpersonationController extends Controller
{
    public function __construct(private TenantImpersonation $impersonation) {}

    public function enter(Request $request, string $token, AgencySubdomain $agencySubdomain): RedirectResponse
    {
        $staff = $this->impersonation->enter($request, $token, $agencySubdomain->get());

        abort_if($staff === null, Response::HTTP_FORBIDDEN, 'This impersonation link is invalid or has expired.');

        return redirect()->to('/tenant');
    }

    public function leave(Request $request): RedirectResponse
    {
        $this->impersonation->leave($request);

        return redirect()->away(rtrim((string) config('app.url'), '/').'/admin/tenants');
    }
}
