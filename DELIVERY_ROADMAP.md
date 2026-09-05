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
   **In progress.**
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
   - Remaining:
     - Relation managers so `Booking` surfaces its travelers/payments inline
       and `Invoice` surfaces its line items (`InvoiceItem` has a model and
       relation but no form UI), instead of unrelated flat resources
       (`BookingTraveler` is currently a flat top-level resource too, and
       should move under `Booking`; documents/add-ons are already nested via
       repeaters, so this is now just travelers + payments + invoice items).
     - "Email invoice to customer" action — no `Mail`/notification
       infrastructure exists yet for outbound tenant email at all, so this
       likely wants to land alongside Phase 11's transactional-email work
       rather than as a one-off `Mail::send()` here.
     - Decide scope: `promo_codes` and `gift_vouchers` tables exist but have no
       models/UI, despite being part of the invoice discount-resolution chain
       (architecture doc §4). Build here or explicitly defer to Phase 10
       (Payments)?
     - Decide now (cheap to fold in, expensive to retrofit): cancellation/
       refund policy tiers and installment/deposit payment schedules on bookings
       — both touch the invoice/payment models being built here.
     - Audit logging: `spatie/laravel-activitylog` is installed but wired into
       zero models — needed before the Phase 12 Audit Log Viewer has anything to
       show, and cheap to add per-model while Policies are already being touched
       in this phase.

8. **Phase 8 — Public Website & API.** Tenant resolution (subdomain or
   `?tenant=slug`), published package/departure read-only API endpoints, public
   inquiry form → auto-creates a Lead, self-service waitlist entry on full fixed
   departures, IP rate limiting, Redis caching. **Not started.** Depends only on
   Phases 5 and 6 (leads + catalog) — does **not** need Phase 7, so it can run
   in parallel with finishing booking & billing rather than waiting on it.

9. **Phase 9 — Customer Portal.** Invite-only account creation, profile, booking
   history (read-only for past trips), traveler/document upload workflows,
   add-on service requests, invoice view + promo/voucher redemption at checkout.
   **Not started.** Depends on Phase 7 (needs real booking/invoice/document data
   to display) — cannot start meaningfully before Phase 7's remaining items
   land.

10. **Phase 10 — Payments.** Verified PayPal webhooks, HBL gateway integration,
    refunds, payment reconciliation. **Not started.** Backend (gateway calls,
    webhook signature verification) only needs Phase 7's Invoice/Payment models
    and can be built in parallel with Phase 9; the customer-facing "pay now"
    button needs Phase 9's portal shell to exist.

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

- [ ] Wire `LogsActivity` on tenant-scoped models (package installed, unused)
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
- [ ] Invite customer to portal (send invite email — needed before Phase 9 can
      work end-to-end)

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

**Promo Codes & Gift Vouchers** — not started (tables exist, no models/UI)

- [ ] Create / list / edit / delete promo code
- [ ] Create gift voucher
- [ ] List / redeem / expire gift voucher

**Add-on Services Catalog & Inventory** — built

- [x] Create / list / edit / delete service
- [x] Create date-based availability slots
- [x] Track booked vs. total slots

**Booking Management** — partial

- [x] Create / list / edit booking
- [ ] View booking detail with nested travelers/payments (documents and
      add-ons are already nested, as repeaters on `BookingResource`'s own
      form; travelers and payments are still separate flat resources)
- [x] Add traveler (works today, but as a standalone resource, not nested under
      Booking)
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
- [ ] Itemized line-item management UI (`InvoiceItem` has no relation manager)
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

- [ ] Invite email sent on booking creation
- [ ] Set password on first login
- [ ] Login
- [ ] Forgot / reset password

**Profile**

- [ ] View profile
- [ ] Edit contact info / passport details
- [ ] Upload avatar

**My Bookings**

- [ ] List current bookings
- [ ] List past bookings (read-only)

**Booking Detail**

- [ ] View itinerary (read-only)
- [ ] View include/exclude list (read-only)
- [ ] View traveler list
- [ ] Notes/messages to staff

**Document Upload**

- [ ] Upload passport / visa / photo
- [ ] View approval status

**Add-on Services**

- [ ] Browse available add-ons
- [ ] Request / purchase add-on (respecting inventory)

**Group/Agency View** (conditional on `customer.type`)

- [ ] Bulk-add traveler rows
- [ ] Bulk document upload

**Invoices & Payment**

- [ ] View invoice history (read-only)
- [ ] Enter promo code / redeem gift voucher at checkout
- [ ] Pay via PayPal
- [ ] Pay via HBL (later)
- [ ] Download invoice PDF

**Notifications**

- [ ] Booking status change
- [ ] Document approval / rejection
- [ ] Payment reminder
