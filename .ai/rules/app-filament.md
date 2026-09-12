---
paths:
  - 'app/Filament/**'
---

# App Filament

## Every money field must go through App\Support\Money / App\Filament\Forms\Components\MoneyInput — never raw cents in the UI
Every money column in this app (Invoice.total, Payment.amount, Booking.total_amount, Package.base_price/sales_price, TenantInvoice.*, etc.) is stored as an integer in minor units (cents). As of 2026-09-12, no Filament form/table/infolist may show or accept raw cents — staff and customers always type/see normal decimal amounts (e.g. "100.00").

- Form input: use `App\Filament\Forms\Components\MoneyInput::make($name)` instead of `TextInput::make($name)->numeric()` for any plain money field — it's a drop-in TextInput factory that converts cents→dollars on hydrate and dollars→cents on dehydrate via `App\Support\Money`, fully chainable (`->label()`, `->required()`, `->live()`, etc.).
- Table/infolist display: use Filament's own native `->money($currency, divideBy: 100)` on `TextColumn`/`TextEntry` — don't hand-roll formatting.
- `Placeholder::make(...)->content(...)` (not a TextColumn/TextEntry, so `->money()` doesn't apply) must format manually via `Money::format($cents, $currency)`.
- A field whose "money-ness" is conditional on a sibling field (PromoCode/GroupDiscountTier's discount_value — money only when discount_type is flat/fixed, otherwise a raw percent 1-99) can't use MoneyInput's automatic hydrate/dehydrate — build its own `afterStateHydrated`/`dehydrateStateUsing` directly against `Money::toDecimal()`/`Money::toCents()`, gated on the sibling field, and format the table column with `formatStateUsing()` the same way (see PromoCodeResource/GroupDiscountTierResource).
- Any `$set('amount', ...)` / `->default(fn () => $invoice->balanceDue())` auto-fill callback that pushes a *_cents value into a MoneyInput-backed field's live state must wrap it in `Money::toDecimal()` first — `$set()`/`->default()` bypass `afterStateHydrated`, so an unconverted cents value shown in a dollar-formatted field would then get multiplied by 100 again on save (see PaymentResource/TenantPaymentResource's invoice_id `afterStateUpdated`).
- A repeater line-item's live recompute (qty × unit_price → total, see InvoiceResource's ItemsRelationManager) must do the arithmetic in decimal dollars now, not integer cents — `$get()`/`$set()` on a MoneyInput-backed field always deal in the display value (dollars), converting to cents only happens once, at final dehydrate.
- A model field with no currency of its own (Booking, Package, FixedDeparture, GroupDiscountTier) formats using the ambient tenant's currency (`auth('tenant')->user()->tenant->currency ?? 'USD'`) since the whole panel is already scoped to one tenant; a model with its own `currency` column (Invoice, Payment, TenantInvoice, TenantPayment, GiftVoucher, Service) uses `$record->currency` instead.
