<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\GiftVoucherPurchase;
use App\Services\InvoicePaymentLinks;
use App\Services\PublicInquiry;
use App\Support\PublicSite;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Public gift voucher purchase (no login). Makes the same invoice as the portal's "Buy a gift
 * voucher" page, then sends the buyer to the no-login payment page; each recipient gets their
 * voucher code by email once the invoice is paid (GiftVoucherPurchase::fulfill()).
 */
class GiftVoucherController extends Controller
{
    /**
     * Preset amounts, in major units of the agency's currency.
     */
    public const PRESET_AMOUNTS = [50, 100, 200, 300, 500];

    public const MIN_AMOUNT = 10;

    public const MAX_AMOUNT = 5000;

    public const MAX_RECIPIENTS = 5;

    public function create(): View
    {
        $this->ensureEnabled();

        return view('public-site.gift-vouchers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_AMOUNT],
            'purchaser.first_name' => ['required', 'string', 'max:255'],
            'purchaser.last_name' => ['required', 'string', 'max:255'],
            'purchaser.email' => ['required', 'email', 'max:255'],
            'purchaser.phone' => ['required', 'string', 'max:30'],
            'for_myself' => ['nullable', 'boolean'],
            'recipients' => ['exclude_if:for_myself,1', 'required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*.first_name' => ['exclude_if:for_myself,1', 'required', 'string', 'max:255'],
            'recipients.*.last_name' => ['exclude_if:for_myself,1', 'required', 'string', 'max:255'],
            'recipients.*.email' => ['exclude_if:for_myself,1', 'required', 'email', 'max:255'],
            'recipients.*.phone' => ['exclude_if:for_myself,1', 'required', 'string', 'max:30'],
            'terms' => ['accepted'],
        ], [], [
            'recipients.*.first_name' => 'recipient first name',
            'recipients.*.last_name' => 'recipient last name',
            'recipients.*.email' => 'recipient email',
            'recipients.*.phone' => 'recipient mobile',
        ]);

        $purchaser = $data['purchaser'];
        $recipients = ($data['for_myself'] ?? false) ? [$purchaser] : array_values($data['recipients']);

        $customer = app(PublicInquiry::class)->resolveCustomer([
            'name' => trim("{$purchaser['first_name']} {$purchaser['last_name']}"),
            'email' => $purchaser['email'],
            'phone' => $purchaser['phone'],
        ]);

        try {
            $invoice = app(GiftVoucherPurchase::class)->createInvoice(
                $customer,
                $purchaser,
                (int) round(((float) $data['amount']) * 100),
                $this->tenant()->currency ?? 'USD',
                $recipients,
            );
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['recipients' => $exception->getMessage()]);
        }

        return redirect()->away(app(InvoicePaymentLinks::class)->url($invoice));
    }

    private function ensureEnabled(): void
    {
        abort_unless(PublicSite::allows($this->tenant(), PublicSite::GIFTS), 404);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }
}
