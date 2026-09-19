---
paths:
  - 'app/Services/PaymentGateways/**,app/Contracts/PaymentGateway.php'
---

# Contracts

## Every PaymentGateway must implement label() — the single source of a gateway's display name
`App\Contracts\PaymentGateway` has a `label(): string` method (added alongside PayLaterGateway) — every "Pay via X" button/option across the app (BuyGiftVoucher's gateway Select, portal ViewInvoice's dynamic payVia* actions, the public payment-link blade view, and the tenant Payment Gateways settings page's save notification) calls `$gateway->label()` instead of hardcoding a `match ($key) { 'paypal' => 'PayPal', ... }` per call site. When adding a new gateway, implement `label()` on the class — don't add another local match/strtoupper anywhere; that's the trap this replaced (three near-duplicate match statements had drifted in appearance before this).

Not every gateway needs real checkout/webhook logic: `PayLaterGateway` is a legitimate `PaymentGateway` implementation with no external processor at all — it's a tenant-toggleable "leave the invoice unpaid, redirect straight back" option, reusing `ResolvesTenantCredentials` with zero required credential keys. `startCheckout()` just returns the given `$returnUrl` (no real external hop), `handleReturn()` returns a `PaymentReturnResult('pending', ...)` and creates no Payment, `handleWebhook()` always returns null, and `refund()` throws since it has nothing to refund. This is the template for any future "no real processor" payment option.
