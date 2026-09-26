<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Auth\CustomerEmailChange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Confirms a customer's email change from the signed link sent to the new address (see
 * CustomerEmailChange). The URL signature is the authorization — no login needed.
 *
 * GET only shows a confirmation button, since mail scanners prefetch links; the actual change is
 * the POST (same pattern as UnsubscribeController).
 */
class CustomerEmailChangeController extends Controller
{
    public function show(Request $request, int $customer, string $hash): View
    {
        $record = $this->resolve($customer);

        return view('customer-email-change', [
            'senderName' => $record->tenant?->name ?? config('app.name'),
            'newEmail' => $record->pending_email,
            'actionUrl' => $record->pending_email === null ? null : $request->fullUrl(),
            'status' => $record->pending_email === null ? 'invalid' : 'confirm',
        ]);
    }

    public function store(int $customer, string $hash, CustomerEmailChange $emailChange): View
    {
        $record = $this->resolve($customer);
        $newEmail = $record->pending_email;
        $confirmed = $emailChange->confirm($record, $hash);

        return view('customer-email-change', [
            'senderName' => $record->tenant?->name ?? config('app.name'),
            'newEmail' => $newEmail,
            'actionUrl' => null,
            'status' => $confirmed ? 'confirmed' : 'invalid',
            'loginUrl' => route('filament.portal.auth.login'),
        ]);
    }

    /**
     * ResolveAgencySubdomain has set the tenant context from the agency subdomain, so the tenant
     * scope only finds this agency's customers — another agency's id is a 404.
     */
    private function resolve(int $customerId): Customer
    {
        return Customer::query()->with('tenant')->findOrFail($customerId);
    }
}
