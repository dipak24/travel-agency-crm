## Delivery Roadmap

Renumbered from 0, in dependency order, replacing the old Phase 1–8 scheme.
Mapping to the old numbers, for anyone looking at prior commits: old Phase 1 →
split across new 0/1/2/3; old Phase 2 → new 6; old Phase 3 → new 5; old Phase 4
→ new 7; old Phase 5 → new 8; old Phase 6 → new 9; old Phase 7 → new 10; old
Phase 8 → split across new 11/12/13.

Near-term phases (0–7) carry a full done/remaining checklist. Phases 8–13 are
scoped but deliberately not broken into exhaustive DB/backend/frontend task
lists yet — that level of detail goes stale before we get there; it'll be filled
in as each phase starts.

---

0. **Phase 0 — Architecture & Foundation Setup.** Laravel + Filament installed,
   all three panels scaffolded (`admin`/`tenant`/`portal`), full database schema
   migrated for every entity in the architecture doc (even ones later phases
   haven't built models/UI for yet). **Completed.**

1. **Phase 1 — Authentication & Super Admin Core.** Three auth guards
   (`super_admin`/`tenant`/`customer`), login pages, seeded accounts.
   **Completed.**
   - Done: guards, login, seeded accounts for all three; Super Admin user CRUD
     UI (`app/Filament/Resources/SuperAdminResource`) — create/list/view/edit/
     deactivate/delete a platform-staff account, `SuperAdminPolicy`, role
     assignment. The seeded `admin@example.com` account is now actually
     assigned the `Super Admin` role (it previously had none, so it couldn't
     have managed anything even though it could log in).
   - Note: Spatie's teams mode requires a non-null `team_id` on every role
     assignment, but the `super_admin` guard has no tenant to scope to — it
     now uses a fixed sentinel `team_id = 0` (`ResolvePlatformTeam` middleware,
     mirroring `TenantContext`/`ResolveTenant`). See `.ai/rules/middleware.md`.

2. **Phase 2 — Role & Permission System.** Spatie permissions in tenant-team
   mode across all three guards; tenant admins can define custom sub-roles
   (Sales, Accountant, Ops) scoped to their own tenant. **Completed** —
   `RolePolicy` (now shared across the `tenant` and `super_admin` guards, scoped
   by `guard_name`/`team_id`), tenant `Role` resource, an admin-panel `Role`
   resource for platform-level roles (Super Admin can now create custom
   platform roles like Support/Billing, not just assign the single seeded
   `Super Admin` role), and role-scoping tests for both guards exist.

3. **Phase 3 — Tenant Management.** Tenant model, `tenant_id` global scope,
   onboarding flow, tenant-isolation tests. **Completed.**
   - Done: the scoping/isolation mechanics themselves, tested
     (`TenantIsolationTest`); Super Admin-facing Tenant CRUD
     (`app/Filament/Resources/TenantResource`) — create (with an owner account
     and a subscription plan in one step, via the existing `TenantOnboarding`
     service), list, edit, suspend/reactivate, soft-delete/restore, reassign
     subscription plan, set trial period (`trial_ends_at`), gated by a new
     `manage tenants` platform permission (`TenantPolicy`); a tenant-facing
     Settings page (`app/Filament/Tenant/Pages/Settings`) for the
     currency/timezone/branding/contact fields that already existed as columns
     on `tenants` but had no form, gated by a new `manage settings` tenant
     permission (Tenant Owner by default). Covered by
     `AdminTenantManagementTest` and `TenantSettingsTest`.
   - Deferred: Impersonate tenant admin (+ start/end audit log + UI banner) —
     this needs its own guard-switching mechanism and depends on the Phase 12
     audit log work, which doesn't exist yet. It was only ever in the
     exhaustive granular checklist below, not this phase's "Remaining" scope,
     so it's tracked there as a standalone open item rather than blocking this
     phase.

4. **Phase 4 — Tenant Admin Panel.** Tenant-side staff management and tenant
   dashboard shell. **Completed** — `Staff` resource, tenant `Role` resource,
   and Filament's default tenant-panel dashboard exist.

5. **Phase 5 — CRM.** Customer management, lead pipeline (Kanban/list,
   source/origin tracking), staff assignment, lead-to-booking conversion.
   **Completed** — `CustomerResource`, `Lead` resource, `LeadPolicy`, and
   `CrmModulesTest` cover isolation, assignment, and conversion.

6. **Phase 6 — Travel Operations (Catalog).** Package CRUD, itinerary editing,
   tenant-wide include/exclude catalog, fixed departures with capacity locking,
   group discount tiers, add-on services + availability. **Completed** — covered
   by `CatalogModulesTest`, `FixedDepartureCapacityTest`, `PackageCatalogTest`.

7. **Phase 7 — Booking & Sales (Billing).** Bookings, travelers, private
   documents, add-ons, invoices, invoice numbering, discounts, payment records.
   **Completed** — matching the Phase 3 precedent, the two items still open
   below don't block this: "email invoice" is structurally blocked on Phase
   11's mail infrastructure (not yet built), and cancellation/installments is
   deliberately deferred pending your own rules, not a missing capability.
   - Done: `Booking`/`BookingTraveler`/`BookingDocument`/`BookingAddon`/
     `Invoice`/`Payment` models, migrations, tenant-panel resources
     (`PaymentResource` included, gated by `PaymentPolicy`), and policies;
     encrypted `passport_no` on `Customer` and `BookingTraveler`; unique
     per-tenant invoice numbering; cross-tenant authorization tests for
     bookings, travelers, documents, add-ons, invoices, and payments;
     private-disk + MIME-checked document uploads; document upload/review
     (approve/reject + rejection reason) and add-on management, both wired as
     inline repeaters on `BookingResource`'s own form rather than standalone
     resources; per-invoice PDF export (`barryvdh/laravel-dompdf`, already
     installed and used by the admin panel's `TenantInvoiceResource`) via a
     `downloadPdf` table action + `resources/views/pdf/invoice.blade.php`,
     covered by `BillingModulesTest`; walk-in/guest customer support on both
     `BookingResource` and `InvoiceResource` — their `customer_id` selects now
     have a `createOptionForm` (`CustomerResource::quickCreateSchema()`) so
     staff can create a brand-new guest `Customer` in a modal without leaving
     the booking/invoice form, gated by `CustomerPolicy::create` (hidden for
     roles like Accountant that can create invoices but not customers); on
     `InvoiceResource`, picking a `booking_id` also auto-fills `customer_id`
     from that booking. Covered by `CrmModulesTest` and `BillingModulesTest`.
   - **Fixed a real blocker**: `InvoiceResource`'s `invoice_no` field was
     `required()` *and* `readOnly()` with nothing to populate it on create —
     every tenant, staff or owner, got a silent "Invoice Number field is
     required" validation failure on every attempt to create an invoice
     through the panel (the model only fills `invoice_no` in its `creating()`
     hook, which runs after Filament's form validation already rejected the
     empty value). Found by exercising `CreateInvoice` end-to-end in a test
     rather than only unit-testing the model/policy. Fixed to match the
     working admin-panel convention (`TenantInvoiceResource`): `invoice_no` is
     now `hiddenOn('create')` and just a read-only display field on edit.
   - **Fixed a permission gap**: `Sales Agent` had `update bookings` but not
     `create bookings` in `PermissionSeeder`, so the only way that role could
     ever produce a `Booking` was via lead conversion (`LeadConversion`
     service, which bypasses `BookingPolicy` entirely) — they could not create
     one directly for a walk-in customer, even though `BookingResource`
     supports it. `Sales Agent` now also has `create bookings`. `Operations`
     and `Accountant` were left as-is (operational/accounting roles, not
     sales) — deliberately not given `create bookings`.
   - Verified end-to-end (not just via Policy unit checks) that both an admin
     (Tenant Owner) and staff (Sales Agent for bookings, Accountant for
     invoices/payments) can create a `Booking`, an `Invoice` from that
     booking, and a `Payment` against that invoice through the actual
     `CreateBooking`/`CreateInvoice`/`CreatePayment` Livewire pages — this is
     what caught both bugs above. Covered by new tests in `CrmModulesTest`
     and `BillingModulesTest`.
   - **Fixed two more UI bugs found the same way** (driving real pages, not
     just reading code): `ListBookings`, `ListBookingTravelers`, and
     `ListInvoices` had no way to reach their `create` page from the index at
     all — every other `ListRecords` page in the app explicitly overrides
     `getHeaderActions()` to return `[CreateAction::make()]` (this project
     does not get that button for free from Filament's own default), but
     these three were missing the override. Fixed by adding it to all three.
     Separately, every page in every panel (List pages especially — Create/
     Edit pages already had it via `HasFullWidthForm`) was rendering inside
     Filament's default constrained `7xl` container instead of full width,
     since none of the three `PanelProvider`s configured a panel-wide max
     content width. Fixed once, panel-wide, via `->maxContentWidth(Width::
     Full)` on `AdminPanelProvider`, `TenantPanelProvider`, and
     `PortalPanelProvider`, rather than patching each of the 20 List pages
     individually. Both regressions are now guarded by
     `TenantPanelPagesTest`.
   - Done since: relation managers so `Booking` surfaces its travelers and
     payments inline, and `Invoice` surfaces its line items — `TravelersRelationManager`
     and a read-only `PaymentsRelationManager` (via a new `Booking::payments()`
     `hasManyThrough(Payment::class, Invoice::class)`, since payments belong to
     an invoice, not a booking directly — write actions are deliberately left
     off this one; use the Invoice's own `PaymentsRelationManager` or
     `PaymentResource` to actually record a payment) on `BookingResource`, and
     `ItemsRelationManager` (with a live qty×unit_price→total calculation) on
     `InvoiceResource`. New `InvoiceItemPolicy` (mirrors `PaymentPolicy`,
     gated on the same `*  invoices` permissions), registered in
     `AppServiceProvider`. `BookingTravelerResource` was deliberately kept
     as a standalone resource alongside the new nested relation manager
     rather than removed — both read/write the same `travelers` relation, and
     removing the flat resource would touch permissions/tests/nav for no
     functional gain. Covered by new tests in `CrmModulesTest` and
     `BillingModulesTest`.
   - Done since: `PromoCode` and `GiftVoucher` models + tenant-panel CRUD
     resources (`PromoCodeResource`, `GiftVoucherResource`, both under the
     existing "Catalog" nav group and gated by the existing
     `TenantCatalogPolicy`/`*  catalog` permissions — same access pattern as
     `GroupDiscountTier`/`Service`, i.e. Tenant Owner only for now, not Sales/
     Accountant). This resolves the roadmap's own "build here or defer"
     question: the old Phase 4 numbering (which these tables were originally
     scoped under) maps to the new Phase 7, so building them here rather than
     deferring to Phase 10 matches what was already decided, just not yet
     acted on. Scope is deliberately just the tenant-side management CRUD —
     actual redemption (applying a promo code or gift voucher to an invoice)
     is a Phase 9 Customer Portal checkout feature per the architecture doc's
     panel breakdown (§5), not built here. Covered by a new test in
     `CatalogModulesTest`.
   - Remaining:
     - "Email invoice to customer" action — no `Mail`/notification
       infrastructure exists yet for outbound tenant email at all, so this
       likely wants to land alongside Phase 11's transactional-email work
       rather than as a one-off `Mail::send()` here.
     - Cancellation/refund policy tiers and installment/deposit payment
       schedules on bookings — **deliberately deferred** (decided 2026-09-06):
       unlike promo codes/vouchers, the architecture doc only sketches this
       (no migration exists — proposed `cancellation_policies` table +
       `cancelled_reason`/`refund_amount` on `bookings`, and a proposed
       `payment_schedule` — deposit %, balance due date — per booking), and
       explicitly leaves the actual tier/schedule rules to be specified
       later. Revisit as its own scoped piece of work once those rules are
       decided, rather than inventing them now — still cheaper to add before
       real booking/invoice data piles up, so don't push it indefinitely.
   - Done since: audit logging — `LogsActivity` + `getActivitylogOptions()`
     wired onto the Phase 7 billing/booking models (`Booking`,
     `BookingTraveler`, `BookingDocument`, `BookingAddon`, `Invoice`,
     `InvoiceItem`, `Payment`; `BookingTraveler` excludes `passport_no` from
     what gets logged, matching its existing encryption/`$hidden` treatment).
     Not extended to every model app-wide — scoped to this phase's own
     models, giving the Phase 12 Audit Log Viewer something to show without
     taking on unrelated phases' models as part of this work.
     **Found and fixed a real pre-existing schema bug while wiring this up**:
     the project's `activity_log` migrations (written against an older
     version of the package's schema) never added the `attribute_changes`
     json column that the installed `spatie/laravel-activitylog: ^5.1`
     unconditionally writes on every single logged event
     (`ActivityLogger::withChanges()` sets it directly, no config toggle
     skips it) — so the very first activity logged by any model, in any
     environment, would have thrown a `QueryException` and silently broken
     whatever action triggered it. Nothing had used the package before this,
     so the gap was latent and untested until now. Fixed by adding the
     column to the original `create_activity_log_table` migration (per this
     repo's pre-launch "edit the original migration" convention) and
     rebuilding the dev DB via `migrate:fresh --seed`. Also fixed causer
     attribution: the package's default causer resolver only ever checks
     `config('auth.defaults.guard')` (`tenant` here), so an action taken on
     the `super_admin` or `customer` guard would have been logged with no
     causer at all — registered a `CauserResolver::resolveUsing()` override
     in `AppServiceProvider` that checks `super_admin` → `tenant` →
     `customer` in order. Covered by new `tests/Feature/AuditLoggingTest.php`.

8. **Phase 8 — Public Website & API.** Tenant resolution (subdomain or
   `?tenant=slug`), published package/departure read-only API endpoints, public
   inquiry form → auto-creates a Lead, self-service waitlist entry on full fixed
   departures, IP rate limiting, Redis caching. **Not started.** Depends only on
   Phases 5 and 6 (leads + catalog) — does **not** need Phase 7, so it can run
   in parallel with finishing booking & billing rather than waiting on it.

9. **Phase 9 — Customer Portal.** Invite-only account creation, profile, booking
   history (read-only for past trips), traveler/document upload workflows,
   add-on service requests, invoice view + promo/voucher redemption at checkout.
   **Functionally complete**, except the two payment gateways (PayPal/HBL),
   which are explicitly Phase 10 scope. Depends on Phase 7 (needs real
   booking/invoice/document data to display) — Phase 7 is complete.
   - Done: invite-only account creation — `Customer` already had guard/
     provider/password-broker config and a `password === null` gate from an
     earlier phase, but nothing actually drove it end-to-end yet. Added: an
     `inviteToPortal` action on the tenant panel's `CustomerResource` that
     mints a password-reset token (`Password::broker('customers')`) and
     emails it via a new `App\Notifications\CustomerPortalInvite`
     (Laravel's built-in `MailMessage` builder, no custom Blade view needed —
     this app's first outbound email, using the existing `MAIL_MAILER=log`
     dev config); `PortalPanelProvider` now has `->passwordReset()` +
     `->authPasswordBroker('customers')` enabled, giving both the invite's
     "set your password" landing page and a genuine "forgot password" flow
     for already-onboarded customers, via the same Filament-provided pages.
     **Fixed two real bugs surfaced while wiring this up** (both recorded in
     `.ai/rules/filament.md`): (1) `Customer::canAccessPanel()`'s
     `password !== null` check created a chicken-and-egg lockout — Filament's
     own `ResetPassword` page checks `canAccessPanel()` *before* saving the
     new password, so an invited customer could never complete setup; fixed
     by dropping that check (a null password already can't match any login
     attempt on its own, so nothing is actually less secure). (2) enabling
     `passwordReset()` without also setting `authPasswordBroker('customers')`
     silently resolved to the app's default `tenant_users` broker instead —
     "forgot password" would have claimed success while emailing no one, and
     completing a reset would have validated the token against the wrong
     table and silently never saved the new password. Covered by
     `tests/Feature/CustomerPortalAuthTest.php` (5 tests: blocked login with
     no password, `canAccessPanel` no longer depends on password presence,
     full invite → set-password → login round trip, invite action hidden
     once a password already exists, and the already-onboarded
     forgot-password path).
   - Done since: Profile — `App\Filament\Portal\Pages\Profile` extends
     Filament's own `EditProfile` auth page (wired via `PortalPanelProvider::
     profile()`), adding phone/address/nationality/passport-number fields and
     an avatar upload on top of Filament's stock name/email/password form.
     New `avatar` column on `customers` (`Customer` now implements Filament's
     `HasAvatar` contract so it actually renders in the portal topbar).
     **Fixed two more inherited defaults that don't hold in a multi-tenant
     app**: Filament's default email-uniqueness check validates against the
     whole table, but this app's real constraint is per-tenant
     (`unique(['tenant_id', 'email'])`) — without a fix, a customer could be
     wrongly blocked from an email already free in their own tenant just
     because an unrelated tenant's customer happens to use it. Added the same
     tenant-scoped check for `phone` too, since it has the same composite
     constraint and would otherwise surface as a raw `QueryException` instead
     of a form error. Also: `attributesToArray()` (used to fill the form)
     respects `Customer::$hidden`, which hides `passport_no` — without
     re-merging the real (auto-decrypted) value in `mutateFormDataBeforeFill`,
     editing the profile would silently blank out an existing passport number
     on save. Covered by `tests/Feature/CustomerPortalProfileTest.php`.
   - Done since: My Bookings + Booking Detail — a new, portal-only
     `App\Filament\Portal\Resources\BookingResource` (list + view pages only;
     `canCreate`/`canEdit`/`canDelete`/`canDeleteAny` all hardcoded `false`,
     query scoped to `customer_id = auth('customer')->id()`) shows a
     customer's own bookings split into Current/Past tabs (by `end_date`
     against today, not `status` — "past trips" is a date concept, not an
     administrative one), and a detail page (Filament Infolist, not a form —
     genuinely read-only) showing the itinerary snapshot, include/exclude
     list, and traveler list (name/DOB/document status — deliberately not
     `passport_no`, kept as sensitive even from the customer it belongs to,
     matching its existing hidden/encrypted treatment elsewhere).
     `BookingPolicy::viewAny`/`view` now accept `TenantUser|Customer` and
     branch — Laravel's `Gate::policy()` is one class per model regardless of
     guard, so a model two guards both touch needs its policy to handle both,
     not a second registration.
     Also built the previously-missing tenant-side piece this needed:
     `booking_include_exclude` had a migration since Phase 0 but no model and
     no way for staff to ever populate it — added `BookingIncludeExclude` and
     a repeater on the tenant `BookingResource` form (same pattern as the
     existing documents/add-ons repeaters), with picking a catalog item
     snapshotting its title/description onto the booking (editing the catalog
     later doesn't retroactively change past bookings, per the architecture
     doc). **Found a real table-naming bug while building this**: Eloquent's
     default convention would look for `booking_include_excludes`, but the
     actual migrated table is `booking_include_exclude` (singular) — fixed
     with an explicit `protected $table`, recorded in `.ai/rules/resources.md`
     since it'll bite any other model backed by a similarly-named
     architecture-doc table.
     "Notes/messages to staff" (architecture doc §5) is genuinely unscoped —
     no data model, no thread/notification design — so rather than building a
     full messaging system, added the minimal version that satisfies the
     literal requirement: a `customer_notes` text column on `bookings`, an
     "Add/edit a note" action on the portal's booking detail page, and a
     read-only display of it on the tenant `BookingResource` edit form. A
     real threaded/notified messaging system, if wanted later, is a separate
     scoped decision. Covered by `tests/Feature/CustomerPortalBookingsTest.php`.
   - Done since: Document upload, add-on requests, group/agency bulk actions,
     invoices + promo/voucher checkout. All four remaining portal modules
     built in one pass:
     - **Document upload**: a `Booking`-detail header action lets a customer
       upload one or more documents at once (`FileUpload::multiple()`) with
       a doc type, creating `pending` `BookingDocument` rows; approval status
       (including the rejection reason) now shows read-only on the same
       detail page. Fixed a real, previously-latent bug found while wiring
       this up: the tenant `BookingResource`'s own staff-facing documents/
       add-ons repeaters never set `uploaded_by`/`added_by` (both NOT NULL,
       no default) — any staff member submitting either repeater would have
       hit a raw `QueryException`. Fixed both via
       `Repeater::mutateRelationshipDataBeforeCreateUsing()`.
     - **Add-on services**: a "Request add-on" action browses active
       `services`; the actual request/inventory logic lives in a new
       `App\Services\BookingAddonRequest` (mirroring the existing
       `FixedDepartureCapacity` service's `lockForUpdate()`-inside-a-
       transaction pattern) so it stays unit-testable without fighting
       Filament's broken mounted-action-form testing (see
       `.ai/rules/feature.md`) — a limited-availability service checks/
       locks/increments its `service_availability` row for the booking's
       `start_date` and throws rather than overbooking; a service with no
       inventory limit just creates the `requested` `BookingAddon` directly.
       **Found a second real bug** while testing this: comparing a
       `date`-cast column with `->where('date', $carbon->toDateString())`
       silently matches nothing, because Eloquent's write path stores a
       `date` cast with a `00:00:00` time suffix regardless of the cast —
       fixed with `->whereDate(...)` instead and recorded in
       `.ai/rules/resources.md`, since it's a trap for any other date-column
       comparison in this codebase.
     - **Group/agency view**: an "Add travelers" bulk-repeater action is
       visible only when `customer.type` is `agency`/`group_leader` (the
       field already existed on `CustomerResource`, just unused by the
       portal until now). Bulk document upload needed no separate gating —
       the document-upload action above already accepts multiple files for
       every customer type, which satisfies the same requirement without an
       arbitrary type check.
     - **Invoices & checkout redemption**: a new portal-only, read-only
       `InvoiceResource` (list scoped to the customer's own non-`draft`
       invoices, Outstanding/Paid tabs; view page shows items and payments)
       plus "Enter promo code", "Redeem gift voucher", and "Download PDF"
       (reusing the tenant panel's existing `pdf.invoice` view) actions.
       `InvoicePolicy::viewAny`/`view` widened for `TenantUser|Customer`,
       same pattern as `BookingPolicy`. The redemption math lives in two new
       services, `App\Services\InvoicePromoRedemption` and
       `InvoiceGiftVoucherRedemption`, both for the same Filament-testing
       reason as the add-on service above. Per the architecture doc's
       discount-resolution order: a promo code is meant to be "applied as a
       separate line item", but `invoice_items.unit_price`/`.total` are
       unsigned columns that cannot hold a negative discount row — rather
       than a schema change with a wider blast radius for a should-have-
       post-MVP feature, the actual discount is applied to the existing
       `invoices.discount`/`.total` fields (which exist for exactly this),
       and a zero-amount `"Promo code: X"` line item is still recorded as
       both an audit trail and the guard against re-applying the same code
       twice on one invoice. A gift voucher is "applied as payment credit,
       not a discount" per the same doc note, which maps cleanly onto a
       normal completed `Payment` row (method `gift_voucher`, new option
       added to the existing method selects) — no schema change needed,
       and it automatically feeds the invoice's existing
       `recalculateStatus()`. `GiftVoucher.value` is treated as the
       voucher's *remaining* balance, decremented on each (possibly
       partial) redemption rather than a separate ledger table.
     - **Notifications**: "Booking status change" and "document approval/
       rejection" are now real mailed notifications (`BookingStatusChanged`,
       `BookingDocumentReviewed`), triggered from `Booking`/`BookingDocument`
       `updated` model events. "Payment reminder" is deliberately NOT built
       here — it depends on the `reminders` table + a scheduling job, which
       is entirely Phase 11 ("Communication & Automation") scope and hasn't
       been started.
     - **Explicitly out of scope for this phase** (by the roadmap's own
       critical path, not an oversight): "Pay via PayPal" and "Pay via HBL"
       are real payment-gateway integrations — Phase 10 owns gateway calls
       and webhook verification; this phase only had to make sure the
       portal invoice-view "shell" they'll attach to exists, which it now
       does.
     Covered by `tests/Feature/CustomerPortalDocumentsAndAddonsTest.php`,
     `tests/Feature/CustomerPortalGroupAgencyTest.php`, and
     `tests/Feature/CustomerPortalInvoicesTest.php`.
   - **Phase 9 is now functionally complete** except the two payment
     gateways, which are explicitly Phase 10's job (see the critical path
     note above Phase 10). Everything else in the Customer Portal granular
     checklist below is checked off.

10. **Phase 10 — Payments.** Verified PayPal webhooks, HBL gateway integration,
    refunds, payment reconciliation. **Not started.** Backend (gateway calls,
    webhook signature verification) only needs Phase 7's Invoice/Payment
    models. The customer-facing "pay now" button's target — Phase 9's portal
    invoice view (`App\Filament\Portal\Resources\InvoiceResource`) — now
    exists, so this phase is unblocked and can start.

11. **Phase 11 — Communication & Automation.** Reminder jobs (documents/
    traveler info/balance due, tenant-configurable schedule), tenant
    marketing/transactional email templates, Super Admin platform email
    templates, mass-email campaigns, `saas_leads` (platform's own top-of-
    funnel), waitlist manual-notify flow, unsubscribe compliance. **Not
    started** — every model here (`EmailTemplate`, `EmailCampaign`, `Reminder`,
    `SaasLead`, etc.) is currently missing, only the tables exist. Reminders
    depend on Phase 7 data; the email-template/campaign piece only depends on
    Phase 0/3/5 (tenants + customers) and could be pulled earlier if a second
    dev track has spare capacity.

12. **Phase 12 — Reports & Analytics.** Per-panel dashboards, cross-tenant Super
    Admin analytics, revenue/conversion/staff-performance reports, precomputed
    nightly aggregate jobs. **Not started.** Needs Phase 7 data at minimum; more
    useful once Phases 10–11 add payment and campaign data too.

13. **Phase 13 — Production Hardening.** Formal security review closeout (2FA
    for `super_admins`/`tenant_users`, payment webhook signature verification if
    not already done in Phase 10, DNS TXT verification for custom domains before
    activating Phase 8's tenant sites), S3 + signed-URL migration for documents,
    feature-limit enforcement wired to `subscription_plans`, subscription
    billing activation, `lockForUpdate()` audit on fixed-departure booking,
    monitoring/backups/CI-CD, load testing on the Phase 8 public API. **Not
    started as a formal pass**, though several individual items are already done
    incrementally (tenant-isolation tests, encrypted PII, private document
    storage, per-model Policies).

---

### Critical path

```
Phase 0 → 1 → 2 → 3 → (4, 5, 6 — can interleave) → 7 → 9 → 10 → soft launch
```

Phase 8 (public site/API) and the email-template half of Phase 11 are **not** on
this critical path — they only depend on Phases 5/6, so they can be built
alongside 7 rather than after it.

---

## Granular Task Checklist (by Panel & Module)

Every module from the architecture doc's Panel-by-Panel breakdown (§5), broken
into individual actions. `[x]` = verified built (resource/policy/test exists in
the codebase today). `[ ]` = not confirmed built — either genuinely not started,
or exists but the specific action wasn't verified at this granularity, so treat
it as a to-check/to-build item either way.

### Admin Panel (`/admin`, guard: `super_admin`)

**Tenant Management** — built (impersonation deferred, see Phase 3 note above)

- [x] Create tenant
- [x] List tenants
- [x] View tenant detail
- [x] Edit tenant
- [x] Suspend / reactivate tenant
- [x] Assign subscription plan
- [x] Set trial period
- [ ] Impersonate tenant admin (+ start/end audit log + UI banner)
- [x] Delete (soft-delete) tenant

**Super Admin User Management** — built

- [x] Create super admin user
- [x] List
- [x] View
- [x] Edit
- [x] Assign role (custom platform roles can now be created via
      "Role Management (super_admin guard)" below; only the single seeded
      `Super Admin` role has actually been created so far — no Support/Billing
      role rows exist yet, that's a data/seeding decision, not a missing
      feature)
- [x] Deactivate / delete

**Role Management (super_admin guard)** — built

- [x] Create role
- [x] List roles
- [x] Edit role permissions
- [x] Delete role (mechanism is in place via `RolePolicy`; no UI delete action is
      wired up on the table yet, matching the tenant `RoleResource` convention)

**Plans & Subscriptions** — built

- [x] Create plan
- [x] List plans
- [x] Edit plan (feature limits: max staff, max bookings/month, etc.)
- [x] Archive/delete plan (via the `is_active` toggle — no hard delete, since
      `tenant_subscriptions.plan_id` restricts deletion of a plan in use)
- [x] Assign plan to a tenant (already existed via `TenantResource`)
- [x] View a tenant's subscription status (trialing/active/past_due/cancelled)
      — badge column on `TenantResource`'s table

**Tenant Billing (Invoices & Transactions)** — built

- [x] Manually create a platform invoice per tenant (subscription, hosting,
      domain, service fee, setup fee, or other line items),
      `TenantInvoiceResource`
- [x] Itemized line items with auto-computed subtotal/total
- [x] Record a transaction/payment against an invoice, supporting multiple
      partial payments and advance/installment/final/refund types,
      `TenantPaymentResource` + `PaymentsRelationManager`
- [x] Invoice status auto-transitions (issued → partially_paid → paid) from
      the payment ledger
- [x] PDF export
- [ ] Automatic recurring invoice generation from `tenant_subscriptions.
      next_billing_at` — still the open Phase 13 "subscription billing
      activation" item; this phase only adds manual invoicing and the schema
      (`tenant_subscription_id` link, `period_start`/`period_end` on line
      items) a future scheduled job would need

**Dashboard**

- [ ] Total tenants + active/trial/suspended counts
- [ ] Recent signups
- [ ] Platform-wide booking/revenue stats

**Global Reports**

- [ ] Top-performing tenants
- [ ] Total bookings/revenue across platform

**Email Template Builder (platform templates)**

- [ ] Create `platform_email_template`
- [ ] List / edit / archive
- [ ] Create campaign (audience: tenants or saas_leads)
- [ ] Send / schedule campaign

**System Settings**

- [ ] Global email/SMS provider config
- [ ] Default currency list
- [ ] Plan/feature toggles
- [ ] Maintenance mode

**Audit Log Viewer**

- [x] Wire `LogsActivity` on the Phase 7 booking/billing models (`Booking`,
      `BookingTraveler`, `BookingDocument`, `BookingAddon`, `Invoice`,
      `InvoiceItem`, `Payment`) — other phases' models (Customer, Lead,
      TenantUser, catalog, etc.) are not yet wired; extend as those phases'
      own audit-logging needs come up, same pattern
- [ ] List activity log entries
- [ ] Filter by tenant / user / date

**Support/Ticketing** — later, not in current scope.

### Tenant Panel (`/tenant`, guard: `tenant`)

**Tenant Dashboard** — default Filament dashboard exists; widgets below
unconfirmed

- [ ] Leads-pipeline widget
- [ ] Bookings-this-month widget
- [ ] Pending-invoices widget
- [ ] Upcoming-trips widget
- [ ] Staff-performance snapshot

**Staff & Role Management** — built

- [x] Create staff
- [x] List staff
- [x] View staff
- [x] Edit staff
- [x] Deactivate staff
- [x] Create custom role
- [x] Assign permissions to role
- [x] Assign role to staff

**Lead Management** — built

- [x] Create lead
- [x] List leads (status pipeline)
- [x] View / edit lead
- [x] Assign lead to staff
- [x] Set follow-up date
- [x] Convert lead → booking
- [ ] Delete lead

**Customer Management** — built

- [x] Create customer
- [x] Create a walk-in/guest customer inline while creating a booking or
      invoice — `CustomerResource::quickCreateSchema()` as a `createOptionForm`
      on both resources' `customer_id` select, gated by `CustomerPolicy`
- [x] List / view / edit customer (contact history, past bookings)
- [ ] Delete customer
- [x] Invite customer to portal (send invite email) — `inviteToPortal` table
      action on `CustomerResource`, visible only while the customer has no
      portal password yet

**Package & Itinerary Management** — built

- [x] Create package (itinerary, base/sales pricing)
- [x] List / view / edit package
- [x] Publish / unpublish package
- [ ] Archive / delete package
- [x] Create / list / edit / delete include-exclude catalog items

**Fixed Departure Management** — built

- [x] Create departure (dates, slots, overbooking buffer)
- [x] List / edit departure
- [x] Capacity locking on booking (`lockForUpdate`, tested)
- [ ] Cancel / close departure

**Group Discount Rules** — built

- [x] Create / list / edit / delete discount tier (pax range, percent/flat)

**Promo Codes & Gift Vouchers** — management CRUD built; redemption built in
Phase 9

- [x] Create / list / edit / delete promo code — `PromoCodeResource`
- [x] Create gift voucher — `GiftVoucherResource`
- [x] List / edit / delete gift voucher — `GiftVoucherResource`
- [x] Redeem gift voucher — happens at Phase 9 customer-portal checkout, via
      `App\Services\InvoiceGiftVoucherRedemption` on the portal invoice view,
      not a tenant-panel action
- [ ] Auto-expire a gift voucher past `expires_at` (status currently only
      changes via manual edit, or implicitly once `InvoiceGiftVoucherRedemption`
      rejects an expired one at redemption time)

**Add-on Services Catalog & Inventory** — built

- [x] Create / list / edit / delete service
- [x] Create date-based availability slots
- [x] Track booked vs. total slots

**Booking Management** — partial

- [x] Create / list / edit booking
- [x] View booking detail with nested travelers/payments — `TravelersRelationManager`
      (full CRUD) and a read-only `PaymentsRelationManager` on `BookingResource`
      (documents/add-ons were already nested as repeaters on the form itself)
- [x] Add traveler — nested `TravelersRelationManager` on `BookingResource`
      (the standalone `BookingTravelerResource` also still exists, kept
      deliberately rather than removed)
- [x] Upload / review document — repeater on `BookingResource`'s form
      (doc type, file upload, approve/reject status + rejection reason)
- [x] Add / manage add-on — repeater on `BookingResource`'s form (service,
      quantity, price, status)
- [x] Record payment — `PaymentResource`
- [ ] Cancel booking

**Invoice & Billing** — partial

- [x] Generate invoice from booking
- [x] List / edit invoice
- [x] Per-tenant invoice numbering (tested)
- [x] Itemized line-item management UI — `ItemsRelationManager` on
      `InvoiceResource`
- [x] PDF export — `downloadPdf` table action on `InvoiceResource`
      (`resources/views/pdf/invoice.blade.php`), tested in
      `BillingModulesTest`
- [ ] Email invoice to customer
- [x] Mark paid / partial / overdue as an explicit action — automatic now,
      driven by the payment ledger (`Invoice::recalculateStatus()`)
- [x] Record a payment against an invoice — `PaymentResource` +
      `InvoiceResource`'s `PaymentsRelationManager`, supports multiple
      partial/advance payments per invoice

**Document Management** — built as a repeater on `BookingResource`, not a
standalone module

- [x] Staff upload document — `FileUpload` field in the documents repeater
- [x] List documents per booking — the repeater itself, scoped to the booking
      being edited
- [x] Approve document — `status` field in the repeater
- [x] Reject document — `status` field + `rejection_reason` in the repeater

**Public Booking Website / Landing Page config** — not started

- [ ] Pick theme
- [ ] Set custom domain / slug
- [ ] DNS TXT record verification before activation
- [ ] Contact settings / branding

**Email Template Builder & Mass Emailing (tenant)** — not started

- [ ] Auto-seed transactional templates per tenant on onboarding
- [ ] Edit transactional template subject/body
- [ ] Create / list / edit / delete marketing templates
- [ ] Create / send / schedule campaign (audience: own customers or own staff
      only)
- [ ] Unsubscribe handling

**Reports** — not started

- [ ] Revenue by month
- [ ] Lead conversion rate
- [ ] Staff performance
- [ ] Booking status breakdown

### Customer Portal (`/portal`, guard: `customer`) — zero resources exist yet

**Auth**

- [ ] Invite email sent automatically on booking creation — not built; a
      manual `inviteToPortal` action on the tenant panel's `CustomerResource`
      covers the same underlying need (staff explicitly triggers the email)
      without assuming every new booking should auto-invite its customer
- [x] Set password on first login — same invite link lands on Filament's
      portal `ResetPassword` page; covered since a null password has nothing
      to "log in to first" otherwise
- [x] Login — panel-level login already existed; now actually reachable
      end-to-end via the invite flow above
- [x] Forgot / reset password — `PortalPanelProvider::passwordReset()` +
      `authPasswordBroker('customers')`

**Profile** — built via `App\Filament\Portal\Pages\Profile` (extends
Filament's own `EditProfile` auth page, wired via `PortalPanelProvider::
profile()`)

- [x] View profile
- [x] Edit contact info / passport details — name/email/phone/address/
      nationality/passport number, plus Filament's own password-change
      section (current password required to change email or password)
- [x] Upload avatar — new `avatar` column on `customers` (mirrors the
      already-existing but never-wired-up `avatar` column on `TenantUser`/
      `SuperAdmin`); `Customer` now implements Filament's `HasAvatar`
      contract so the uploaded image actually shows in the portal's topbar,
      not just sitting unused in storage

**My Bookings** — built via the portal's own `BookingResource` (list/view
only — no create/edit/delete)

- [x] List current bookings — "Current" tab (`end_date` null or `>= today`)
- [x] List past bookings (read-only) — "Past" tab (`end_date < today`); the
      whole resource is read-only anyway, not just this tab

**Booking Detail** — built as a Filament Infolist (not a form)

- [x] View itinerary (read-only) — renders the `booked_itinerary` snapshot;
      only populated today for bookings created via lead conversion, since
      `BookingResource`'s own create form has no package/itinerary picker
      (a pre-existing gap outside this phase's scope)
- [x] View include/exclude list (read-only) — new `BookingIncludeExclude`
      model + tenant-side repeater to actually populate it (see Phase 9 notes
      above)
- [x] View traveler list — name/DOB/document status; deliberately not
      passport number
- [x] Notes/messages to staff — minimal version: a single `customer_notes`
      text field + an edit action, not a full threaded messaging system (see
      Phase 9 notes above for why)

**Document Upload** — built via a header action on the portal booking detail
page

- [x] Upload passport / visa / photo — `FileUpload::multiple()`, one action
      covers uploading several documents at once
- [x] View approval status — status badge + rejection reason, read-only

**Add-on Services** — built via a header action + `App\Services\
BookingAddonRequest`

- [x] Browse available add-ons — searchable select of active `services`
- [x] Request / purchase add-on (respecting inventory) — limited-availability
      services check/lock/increment `service_availability` inside a
      transaction; unlimited ones just create the request

**Group/Agency View** (conditional on `customer.type`) — built

- [x] Bulk-add traveler rows — "Add travelers" repeater action, visible only
      for `agency`/`group_leader` customers
- [x] Bulk document upload — the Document Upload action above already
      accepts multiple files for every customer type; no separate gating
      needed for the same requirement

**Invoices & Payment** — built via a new portal-only, read-only
`InvoiceResource`

- [x] View invoice history (read-only) — Outstanding/Paid tabs, scoped to
      the customer's own non-draft invoices
- [x] Enter promo code / redeem gift voucher at checkout —
      `App\Services\InvoicePromoRedemption` / `InvoiceGiftVoucherRedemption`
      (see Phase 9 notes above for the discount-vs-payment-credit design)
- [ ] Pay via PayPal — explicitly Phase 10 (gateway integration); this
      phase only had to get the invoice-view "shell" ready, which it now is
- [ ] Pay via HBL (later) — same as above, Phase 10
- [x] Download invoice PDF — reuses the tenant panel's existing
      `pdf.invoice` Blade view

**Notifications**

- [x] Booking status change — `App\Notifications\BookingStatusChanged`,
      mailed on `Booking`'s `updated` event when `status` actually changed
- [x] Document approval / rejection — `App\Notifications\
      BookingDocumentReviewed`, mailed on `BookingDocument`'s `updated`
      event
- [ ] Payment reminder — deliberately deferred: this needs the `reminders`
      table + a scheduling job, which is Phase 11 ("Communication &
      Automation") scope and hasn't been started yet
