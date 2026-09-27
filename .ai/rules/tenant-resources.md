---
paths:
  - 'app/Services/Booking*.php,app/Filament/Tenant/Resources/BookingResource*'
---

# Tenant Resources

## Booking totals are derived by BookingPricing — never set total_amount by hand
Decided 2026-09-27. bookings.total_amount and its parts (base_amount, group_discount_amount, inclusions_adjustment, addons_amount) are written only by App\Services\BookingPricing::recalculate(). Price = per_person_price × pax − group tier (fixed/private groups only; package tier beats global) ± inclusion rows vs their package default (default_included) + approved/booked add-ons ± manual_adjustment (reason required). Any code that changes pax, rows, add-on status or price must call recalculate() afterwards. Booking-type rules (fixed group takes dates from its departure, end date = start + days − 1, seat moves) live in BookingSchedule; seats are held by any non-cancelled booking with a fixed_departure_id. Portal/reads use quoteFor() (no writes).
