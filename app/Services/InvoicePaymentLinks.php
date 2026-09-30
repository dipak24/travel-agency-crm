<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use App\Support\AgencySubdomain;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * No-login payment links for invoices. CST can email one or copy it and send it themselves
 * (WhatsApp, SMS, chat): the customer opens it and pays with whatever payment methods the agency
 * has enabled, without a portal account. Links are signed (tamper-proof), expire, and are rooted
 * at the agency's own subdomain so the page carries its branding.
 */
class InvoicePaymentLinks
{
    public const DEFAULT_DAYS = 14;

    /**
     * @var array<int, string>
     */
    public const EXPIRY_OPTIONS = [
        3 => '3 days',
        7 => '7 days',
        14 => '14 days',
        30 => '30 days',
        60 => '60 days',
    ];

    /**
     * `$offerPayLater` is only for the public booking pages' own redirect to payment: a link staff
     * copy or send never offers Pay Later. The flag is part of the signature, so it can't be added
     * to a link by hand.
     */
    public function url(Invoice $invoice, int $days = self::DEFAULT_DAYS, bool $offerPayLater = false): string
    {
        $invoice->loadMissing('tenant');
        $parameters = ['invoice' => $invoice->getKey(), ...($offerPayLater ? ['pay_later' => 1] : [])];
        $sign = fn (): string => URL::temporarySignedRoute('public.pay.show', $this->expiresAt($days), $parameters);

        return $invoice->tenant !== null ? AgencySubdomain::within($invoice->tenant, $sign) : $sign();
    }

    public function expiresAt(int $days): Carbon
    {
        return now()->addDays(array_key_exists($days, self::EXPIRY_OPTIONS) ? $days : self::DEFAULT_DAYS);
    }

    /**
     * Whether staff can hand out a payment link for this invoice: it has been issued (not a draft
     * or cancelled), something is still owed, and the agency has at least one method enabled that
     * takes a payment — Pay Later alone doesn't count (online methods depend on the plan's
     * "Online payments" feature and the agency's gateway settings).
     */
    public function canBePaidOnline(Invoice $invoice): bool
    {
        return ! in_array($invoice->status, ['draft', 'cancelled'], true)
            && $invoice->balanceDue() > 0
            && app(PaymentGatewayResolver::class)->payNowFor($invoice) !== [];
    }

    /**
     * A ready-to-send message for chat apps.
     */
    public function shareMessage(Invoice $invoice, string $url, int $days): string
    {
        $invoice->loadMissing(['tenant', 'customer', 'booking']);
        $name = $invoice->customer?->name ? " {$invoice->customer->name}" : '';
        $trip = $invoice->booking?->trip_name ? " for {$invoice->booking->trip_name}" : '';

        return "Hi{$name}, please use this secure link to pay invoice {$invoice->invoice_no}{$trip} "
            .'('.Money::format($invoice->balanceDue(), $invoice->currency).' due). No login needed: '
            .$url.' — the link is valid until '.$this->expiresAt($days)->format('j M Y').'. '
            .'Thank you, '.($invoice->tenant?->name ?? 'our team').'.';
    }
}
