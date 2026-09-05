---
paths:
  - 'database/seeders/PermissionSeeder.php,app/Policies/BookingPolicy.php'
---

# Seeders Policies

## Sales Agent has create bookings; verify new tenant permissions end-to-end, not just via Policy unit checks
`Sales Agent` previously had `update bookings` but not `create bookings`, so the only way that role could produce a `Booking` was via `LeadConversion` (which calls `Booking::create()` directly and bypasses `BookingPolicy` entirely) — they could not create one directly for a walk-in/guest customer even though `BookingResource` supports it. Fixed 2026-09-05: `Sales Agent` now also has `create bookings`. `Operations` and `Accountant` are deliberately NOT given `create bookings` (operational/accounting roles, not sales).

More generally: when adding or changing a tenant role's permissions, verify by driving the actual Filament Create page for that role via `Livewire::actingAs($user, 'tenant')->test(CreateX::class)->set(...)->call('create')->assertHasNoFormErrors()`, not just by asserting the Policy class directly. A Policy unit check can pass while the real form is unreachable or unsubmittable (see the sibling rule on `required()+readOnly()` fields) — this is exactly how both the `Sales Agent` permission gap and the `InvoiceResource.invoice_no` bug were actually found.
