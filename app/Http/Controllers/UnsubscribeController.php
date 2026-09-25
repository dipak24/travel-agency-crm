<?php

namespace App\Http\Controllers;

use App\Models\EmailUnsubscribe;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Marketing-email opt-out, reached from the signed link in every campaign email (see
 * CampaignSender::unsubscribeUrl()). The URL signature is the authorization — no login. `scope`
 * is a tenant id (opt out of that tenant's campaigns) or `platform` (opt out of the platform's).
 *
 * GET only shows a confirmation button, since mail scanners prefetch links; the actual opt-out is
 * the POST, which also serves RFC 8058 one-click unsubscribes from mail providers.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request): View
    {
        [$tenant, $email] = $this->resolve($request);

        return view('unsubscribe', [
            'senderName' => $tenant?->name ?? config('app.name'),
            'email' => $email,
            'actionUrl' => $request->fullUrl(),
            'unsubscribed' => false,
        ]);
    }

    public function store(Request $request): View|Response
    {
        [$tenant, $email] = $this->resolve($request);

        EmailUnsubscribe::query()->firstOrCreate(
            ['tenant_id' => $tenant?->id, 'email' => $email],
            ['unsubscribed_at' => now(), 'source' => 'unsubscribe_link'],
        );

        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent(200);
        }

        return view('unsubscribe', [
            'senderName' => $tenant?->name ?? config('app.name'),
            'email' => $email,
            'actionUrl' => null,
            'unsubscribed' => true,
        ]);
    }

    /**
     * @return array{0: ?Tenant, 1: string}
     */
    private function resolve(Request $request): array
    {
        $scope = (string) $request->query('scope');
        $email = Str::lower((string) $request->query('email'));

        abort_if($email === '', 404);

        $tenant = $scope === 'platform' ? null : Tenant::query()->findOrFail((int) $scope);

        return [$tenant, $email];
    }
}
