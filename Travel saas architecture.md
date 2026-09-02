# Travel Business Management SaaS — Architecture & Feature Plan

**Stack:** Laravel + FilamentPHP · MySQL/PostgreSQL · Multi-Tenant

---

## 1. High-Level Architecture

You have **3 layers of users**, which maps cleanly to **3 Filament Panels**:

```
┌─────────────────────────────────────────────────────────────┐
│  PANEL 1: /admin  →  Super Admin Panel (you, the SaaS owner) │
│  - Manages all tenants (travel agencies)                     │
│  - Platform billing, plans, global staff                     │
└─────────────────────────────────────────────────────────────┘
                              │ creates & manages
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  PANEL 2: /tenant  →  Tenant Panel (a travel agency)         │
│  - Tenant Admin, Staff (sales agent, accountant, ops)         │
│  - Leads → Bookings → Invoices → Payments                    │
└─────────────────────────────────────────────────────────────┘
                              │ creates & manages
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  PANEL 3: /portal → Customer Portal (end traveler/agency)     │
│  - View bookings, upload docs, pay, add-on services            │
└─────────────────────────────────────────────────────────────┘
```

### Why 3 Panels (not 1 with role-based visibility)

Filament supports multiple panels in one Laravel app, each with its own auth
guard, login page, theme, and middleware. This is the cleanest approach because:

- Super Admin, Tenant staff, and Customers should **never** share a login screen
  or session guard (security boundary).
- Each panel can have a completely different navigation/UX (internal staff tool
  vs. self-service customer portal).
- You can scale/deploy them separately later if needed (e.g., customer portal on
  a subdomain).

**Recommended packages:**

| Purpose                                | Package                                                                                                                                                                                                                                                |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Multi-panel                            | `filament/filament` (native multi-panel support)                                                                                                                                                                                                       |
| Multi-tenancy (data isolation)         | `stancl/tenancy` (single DB, `tenant_id` scoping) **or** Filament's built-in tenant feature                                                                                                                                                            |
| Roles & permissions                    | `spatie/laravel-permission`                                                                                                                                                                                                                            |
| Activity/audit log                     | `spatie/laravel-activitylog`                                                                                                                                                                                                                           |
| Media/document uploads                 | `spatie/laravel-medialibrary`                                                                                                                                                                                                                          |
| PDF invoices                           | `barryvdh/laravel-dompdf` or `spatie/laravel-pdf`                                                                                                                                                                                                      |
| Notifications (email/SMS)              | Laravel Notifications + `laravel/vonage` or local SMS gateway                                                                                                                                                                                          |
| Transactional/marketing email delivery | A real ESP (Postmark, SendGrid, Mailgun, or Amazon SES) — not raw SMTP — for deliverability, bounce/unsubscribe webhooks, and rate limits at campaign volume                                                                                           |
| Rich template editing                  | Filament's built-in rich-text editor (TipTap) is fine for merge-tag-driven transactional templates; for a proper drag-and-drop marketing email builder with email-client-safe HTML, look at embedding Unlayer's editor or a GrapesJS newsletter preset |
| Payments                               | Custom PayPal SDK integration; HBL via their payment gateway API (Pakistan bank — usually a custom REST integration, not a Composer package)                                                                                                           |

---

## 2. Multi-Tenancy Strategy (Important Decision)

Two realistic options for your scale:

### Option A — Single Database, Shared Schema (Recommended to start)

- One database, every tenant-scoped table has a `tenant_id` column.
- Use a **global scope** (or `stancl/tenancy`'s single-database mode) to
  auto-filter every query by the logged-in tenant.
- **Pros:** simple to build, cheap to host, easy migrations, easy cross-tenant
  reporting (for you as Super Admin).
- **Cons:** requires discipline — every query/model must be tenant-scoped or you
  risk data leaks.

### Option B — Database-per-Tenant

- Each tenant gets its own database (`stancl/tenancy` supports this natively).
- **Pros:** strongest data isolation, easy to export/delete a single tenant's
  data.
- **Cons:** heavier ops (migrations must run per-tenant DB), costlier at scale,
  harder for you to run cross-tenant Super Admin reports.

**Recommendation:** Start with **Option A (single DB + tenant_id)**. It's faster
to build in Filament, and for a travel SaaS you'll likely want cross-tenant
analytics anyway (e.g., "which tenants are most active"). Migrate to per-tenant
DBs later only if a client demands strict data residency.

**MySQL vs PostgreSQL:** Either works fine with Laravel/Filament. PostgreSQL is
slightly better if you'll do heavy JSON columns (e.g., flexible "trip details"
or "custom fields" per tenant) and better full-text search. MySQL is fine if
your team is more familiar with it and hosting is simpler/cheaper. Given no
strong extra requirement, **either is safe — pick what your team knows best.**

---

## 3. Roles & Permissions Model

Use `spatie/laravel-permission` with **guards per panel** plus a `role` enum for
quick checks.

| Guard         | Roles                                                                                     |
| ------------- | ----------------------------------------------------------------------------------------- |
| `super_admin` | Super Admin, Platform Staff (e.g., Support, Billing Ops)                                  |
| `tenant`      | Tenant Owner/Admin, Tenant Staff (Sales Agent, Accountant, Operations, Guide Coordinator) |
| `customer`    | Customer (Individual), Agency Booker, Group Leader                                        |

Each tenant admin should be able to define **custom sub-roles within their own
tenant** (e.g., "Sales Agent" who can only see leads/bookings, "Accountant" who
only sees invoices) — this is your "Tenant staff management" requirement. Build
this as tenant-scoped roles (Spatie supports "teams" mode, which maps perfectly
to `tenant_id` as the team).

---

## 4. Core Database Schema (Entities & Relationships)

```
tenants
  id, name, subdomain/slug, plan, status (active/suspended/trial),
  address, phone_number, mobile_number,
  billing_email, timezone, currency, created_at, updated_at

-- AUTH SPLIT (see note below the schema for the reasoning):
super_admins
  id, name, email, password, status, avatar, created_at
  -- NO tenant_id at all — structurally impossible to scope one to a tenant

tenant_users            (renamed from `users` — staff/admin of a tenant)
  id, tenant_id (NOT NULL — enforced at the DB level, not just app logic),
  name, email, password, status, avatar,
  designation, department, joining_date          -- merged-in staff fields
  -- this table IS your staff directory now — no separate `staff` table

customers               (also the auth table for the customer-portal guard)
  id, tenant_id, name, email, password, remember_token,   -- auth columns
  phone, type (individual/agency/group_leader),
  address, passport_no, nationality, notes
  -- password/remember_token stay NULL until the invite is accepted;
  --  no separate "customer_users" table needed for a normal 1 login = 1
  --  customer case (see open note below if agencies need multiple logins)

roles / permissions   (spatie tables, team_id = tenant_id; super_admins use
                       a separate guard so their roles never mix with tenant_users)

leads
  id, tenant_id, customer_id (nullable — a lead is a raw inquiry/contact-us
  submission; we do NOT create a customer record at lead stage. customer_id
  is only ever filled in once the lead converts to a booking, so the lead
  keeps its history linked backward even though it started anonymous),
  source (website/whatsapp/referral/agent), origin (public_website/manual/
  whatsapp/referral/agent), destination, trip_type, pax_count, budget_range,
  status (new/contacted/negotiating/won/lost),
  assigned_staff_id, follow_up_date, notes

bookings
  id, tenant_id, lead_id (nullable), customer_id, package_id (nullable —
  null if it's a fully custom trip not built from the package catalog),
  fixed_departure_id (nullable), trip_name (snapshot text, so renaming/
  archiving a package later doesn't rewrite booking history),
  booked_itinerary (SNAPSHOT of the package's itinerary at booking time —
  include/exclude items are NOT stored here, see booking_include_exclude
  below), description, start_date, end_date, pax_count,
  status (pending/confirmed/ongoing/completed/cancelled),
  total_amount, created_by_staff_id
  -- booked_itinerary and description are written by TENANT STAFF ONLY;
  --  the customer portal shows both as read-only text, no edit form at all

booking_travelers   (for group bookings — each traveler's details)
  id, booking_id, name, passport_no, dob, document_status

booking_documents
  id, booking_id, uploaded_by (customer/staff), file_path,
  doc_type (passport/visa/photo/insurance), status (pending/approved/rejected)

booking_addons        (extra services added by customer or staff)
  id, booking_id, service_id, price (snapshot at time of add — service's
  catalog price may change later), status, added_by

packages               (tenant's trip/tour products, e.g. "Everest Base Camp Trek")
  id, tenant_id, name, slug, package_code (short public-facing reference,
  e.g. "EBC-14D" — distinct from the internal `id`), description,
  itinerary (richtext or JSON — day-by-day plan lives as one field on the
  package itself now, no separate table; simpler to edit and version;
  TENANT-EDITABLE ONLY — customer/booker portal renders this read-only,
  same as include_exclude below),
  base_price (tenant's cost/floor), sales_price (what the customer pays),
  duration_days, is_public (shown on public website),
  status (draft/published/archived)

include_exclude        (TENANT-WIDE catalog, NOT tied to a package — a
                        tenant sells the same package at different tiers
                        [luxury/normal/budget], so what's included isn't
                        fixed per package, it depends on what the specific
                        customer bought. Tenant maintains one reusable
                        list of possible items here; customer/booker gets
                        VIEW ONLY, never edit access, at every stage below)
  id, tenant_id, type (include/exclude), title, description, sort_order

booking_include_exclude  (per-booking SELECTION from the include_exclude
                          catalog — staff check which items actually apply
                          to THIS booking, e.g. "Luxury Hotel" checked for
                          a luxury buyer, unchecked for a normal-tier buyer
                          on the same package. Title/description are
                          copied in as a snapshot at selection time, so
                          editing the catalog item later never rewrites
                          an existing booking's record)
  id, booking_id, include_exclude_id (nullable — nullable so staff can
  also add a one-off custom item not in the catalog), type (include/
  exclude), title, description, sort_order

fixed_departures        (tenant-defined fixed departure dates for a package)
  id, tenant_id, package_id, start_date, end_date,
  total_slots, overbooking_buffer (tenant-configurable, default 0),
  booked_slots, price_override (nullable),
  status (open/full/closed/cancelled)
  -- "full" = booked_slots >= total_slots; still bookable until
  --  booked_slots >= total_slots + overbooking_buffer, then "closed"

group_discount_tiers    (tenant-defined pricing breaks by group size)
  id, tenant_id, package_id (nullable = applies to all packages),
  min_pax, max_pax, discount_type (percent/flat), discount_value

promo_codes
  id, tenant_id, code, discount_type (percent/flat), discount_value,
  usage_limit, used_count, valid_from, valid_until, is_active
  -- always tenant_id scoped — never redeemable across tenants

gift_vouchers
  id, tenant_id, code, value, currency, issued_to (customer_id nullable),
  status (unredeemed/partially_redeemed/redeemed/expired), expires_at

booking_waitlist       (renamed from departure_waitlist — kept deliberately
                        simple: it's just a record, not a workflow engine)
  id, tenant_id, fixed_departure_id, customer_id (nullable — may be a
  lead not yet a customer), pax_requested, position, status
  (waiting/notified/converted/expired), notified_at, notified_by_staff_id
  -- on creation: system just sends the tenant admin/staff an email
  --  ("new waitlist entry for [departure]") — no auto-timer, no auto-cancel.
  --  Staff manage everything from there manually: deciding who to notify,
  --  converting to a booking, or letting an entry go stale. Same manual-
  --  first philosophy applies to overdue bookings/invoices — the system
  --  flags them, staff decide what to do, nothing auto-cancels.

-- Discount resolution order on an invoice (highest priority wins,
-- does not stack unless tenant explicitly allows a promo/voucher on top):
--   1. Manually negotiated staff discount (if present, overrides group discount)
--   2. Group discount tier (only applied if no manual discount set)
--   3. Promo code (always stackable — applied as a separate line item)
--   4. Gift voucher (always stackable — applied as payment credit, not a discount)

-- NO separate `booking_requirements` table. "What's required" is computed
-- on the fly at reminder-check time by querying existing tables directly:
--   - documents missing/pending  → query booking_documents by doc_type
--   - traveler info incomplete   → query booking_travelers for empty fields
--   - balance due                → query invoices/payments for outstanding total
-- This avoids maintaining a duplicate ledger that can drift from real data.
-- `reminders` below just logs what was actually sent, to prevent re-sending.

reminders                (queued/sent reminder log — NOT a requirements list)
  id, tenant_id, booking_id, requirement_type (document/traveler_info/
  payment), recipient_type (staff/customer/both), channel (email — sms/
  whatsapp planned later), reminder_rule (e.g. "7 days before trip if
  unsatisfied"), sent_at, status

services               (tenant-defined add-on catalog, e.g. "KTM city tour")
  id, tenant_id, name, description, price, is_active,
  has_limited_availability (bool)

service_availability    (slots/inventory — only relevant when a service's
                         has_limited_availability = true)
  id, service_id, date, total_slots, booked_slots

public_lead_pages       (per-tenant public booking site config, if using a
                         builder rather than a fully custom site)
  id, tenant_id, custom_domain/slug, theme, contact_settings, is_active

subscription_plans      (Super Admin side — built now, billed later:
                         design the tables even if you don't charge yet)
  id, name, price, billing_cycle, feature_limits (json), is_active

tenant_subscriptions
  id, tenant_id, plan_id, status (trialing/active/past_due/cancelled),
  starts_at, ends_at, next_billing_at

invoices
  id, tenant_id, booking_id, customer_id, invoice_no, amount, tax,
  discount, total, status (draft/sent/paid/partially_paid/overdue/cancelled),
  due_date, issued_by

invoice_items
  id, invoice_id, description, qty, unit_price, total

payments
  id, tenant_id, invoice_id, amount, method (paypal/hbl/bank_transfer/cash),
  status (pending/success/failed/refunded), transaction_ref, paid_at

-- REVISED per your correction: marketing templates are NOT created by
-- Super Admin — they're 100% tenant-created and tenant-isolated. Only the
-- fixed SYSTEM transactional types (forgot password, welcome email, etc.)
-- are system-seeded, and even those are just seeded copies tenants edit
-- the content of — Super Admin never owns or touches a tenant's copy.

email_template_types    (FIXED catalog of system transactional emails —
                         seeded by you the developer via migration/seeder,
                         not created through any panel by anyone. This is
                         what the app's code actually triggers by `key`)
  id, key (forgot_password/reset_password/welcome_email/
  booking_confirmation/invoice_sent/document_approved/document_rejected/
  reminder_due/customer_invite/staff_invite/etc.), name, description,
  default_subject, default_body_html, available_merge_tags (json)

email_templates          (a TENANT'S own copy — every row belongs to
                          exactly one tenant, no exceptions, no shared or
                          globally-visible rows of any kind)
  id, tenant_id (NOT NULL), email_template_type_id (nullable —
  SET for a transactional template auto-seeded from email_template_types
  when the tenant is created; NULL for a marketing template the tenant
  created themselves from scratch), category (transactional/marketing),
  name, subject, body_html, status (active/draft), updated_by, updated_at
  -- transactional rows: auto-created per tenant at onboarding time from
  --  email_template_types' defaults. Tenant/admin can edit subject/body
  --  content only — cannot delete the row or invent a new transactional
  --  type, since the system relies on `email_template_type_id.key` to
  --  know which email to fire on which event
  -- marketing rows: fully created, owned, and saved by the tenant/admin
  --  themselves for reuse across campaigns — completely isolated per
  --  tenant_id; no cross-tenant visibility, no Super-Admin involvement

platform_email_templates  (Super Admin's OWN templates — structurally
                           separate table, never touches tenant/customer
                           data, so there's no accidental crossover)
  id, category (marketing/transactional — e.g. "tenant_welcome",
  "saas_lead_nurture", "platform_maintenance_notice"), name, subject,
  body_html, status (draft/active/archived), created_by

saas_leads               (Super Admin's OWN top-of-funnel contacts —
                          people evaluating becoming a tenant on your
                          platform. Deliberately a SEPARATE table from a
                          tenant's own `leads` table, which is about trip
                          inquiries — these two "leads" concepts must
                          never be confused or merged)
  id, name, email, company, status (new/contacted/trial/converted/lost),
  source, notes

email_campaigns          (mass emailing — scope is STRICTLY enforced by
                          which owner created it, not just a filter)
  id, owner_type (tenant/super_admin), tenant_id (NOT NULL when
  owner_type = tenant; NULL when owner_type = super_admin),
  template_id (points to email_templates.id when owner_type=tenant, or
  platform_email_templates.id when owner_type=super_admin — enforce this
  pairing in application code, not just casually), name, subject_override
  (nullable), audience_type, audience_filter (json), scheduled_at,
  status (draft/scheduled/sending/sent/cancelled/failed),
  total_recipients, sent_count, failed_count, created_by
  -- audience_type is constrained BY owner_type:
  --   owner_type = tenant       → audience_type IN (customer, staff)
  --                                 — i.e. a tenant's OWN customers or
  --                                 their OWN tenant_users, nothing else
  --   owner_type = super_admin  → audience_type IN (tenant, saas_lead)
  --                                 — i.e. existing tenant admins/agencies,
  --                                 or your own saas_leads prospects.
  --                                 Super Admin does NOT get an audience
  --                                 option to email tenants' end customers
  --                                 directly — see note below on why

email_campaign_recipients   (per-recipient send log for a campaign)
  id, campaign_id, recipient_type (customer/tenant_user/tenant/saas_lead),
  recipient_id (nullable), email,
  status (pending/sent/failed/bounced/unsubscribed), sent_at

email_unsubscribes        (compliance — required for marketing sends;
                           transactional emails are exempt)
  id, tenant_id (nullable — NULL = unsubscribed from Super Admin's own
  sends; otherwise scoped to that one tenant's marketing sends only —
  unsubscribing from one tenant's marketing never affects another tenant),
  email, unsubscribed_at, source (marketing_campaign/manual)


activity_logs          (spatie activitylog — who did what, when)
notifications           (Laravel notifications table)
```

### Why split into `super_admins` / `tenant_users` / `customers` (your question)

Good instinct, and worth doing. Three reasons:

1. **Structural safety, not just app-logic safety.** With one shared `users`
   table and a nullable `tenant_id`, a bug in a query's `WHERE` clause could
   theoretically return a super admin row in a tenant-scoped query. With
   `super_admins` as a completely separate table, that class of bug becomes
   impossible, not just unlikely.
2. **`tenant_id NOT NULL` on `tenant_users`** means the database itself rejects
   any attempt to create a tenant staff record without a tenant — one less thing
   your application code has to guard against.
3. **It matches your 3 guards 1:1** (`super_admin`, `tenant`, `customer`), which
   is exactly how Filament's multi-panel auth expects things to be modeled —
   each panel's guard points at its own Eloquent model. This isn't extra work,
   it's the natural shape for what you're already building.

I renamed `users` → `tenant_users` as you suggested, and folded the old separate
`staff` table's fields (`designation`, `department`, `joining_date`) directly
into it — see the staff-table note below.

### Why no separate `staff` table (your question)

Every `tenant_user` **is** staff — there's no case in your spec where a
tenant_user exists but isn't staff. Keeping
`designation`/`department`/`joining_date` as columns on `tenant_users` itself
avoids an unnecessary 1:1 join on every staff-list query, with no loss of
functionality. Removed.

### Why no separate `booking_requirements` table (your question)

You're right to question it — it would just be a cache of information that
already lives elsewhere (documents, traveler records, invoices) and caches like
that tend to drift out of sync. Removed; the reminder job queries the source
tables directly each time it runs. The only thing that still needs to persist is
the `reminders` log itself, so you don't send the same reminder twice.

### One item I did _not_ silently change — `services` / `service_availability`

Your note said these might not be needed since `booking_addons` already
represents "a service." I kept both tables as a **default assumption**, because
removing them would also remove per-date slot inventory — which you'd earlier
confirmed you wanted for add-ons ("Yes" to limited availability). If you're fine
dropping capacity limits on add-ons entirely (just free-text/priced line items,
no inventory), I'll remove both tables and simplify `booking_addons` back to a
free-text `service_name` + `price`. Otherwise, keep as-is: `services` is the
reusable catalog (name/price/whether it's capacity-limited),
`service_availability` holds the actual date-based slot counts only for services
that need it, and `booking_addons.service_id` now links to the catalog instead
of duplicating the name as free text.

### One thing I deliberately restricted — Super Admin cannot mass-email tenants' end customers directly

You said Super Admin's mass emailing goes to "their own (customer or travel
agency)" — I've read "travel agency" as the tenants themselves (which the schema
supports directly via `audience_type = tenant`), and "customer" as **your own
SaaS prospects** (`saas_leads` — people evaluating signing up as a tenant), not
a tenant's actual travelers. I did this deliberately rather than silently: a
tenant's customer list is _their_ business relationship — if Super Admin could
mass-email a tenant's travelers directly, that undermines the trust boundary the
entire multi-tenant model depends on (the same reason AWS can't email your app's
users, or Shopify can't email a merchant's shoppers). If you actually meant
Super Admin should be able to reach tenants' end customers too — e.g. for a
platform-wide travel advisory — say so and I'll add it as an explicit,
clearly-labeled exception rather than folding it into the default audience
options.

---

## 5. Panel-by-Panel Feature Breakdown

### PANEL 1 — Super Admin (`/admin`)

| Module                                                  | Features                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Dashboard**                                           | Total tenants, active/trial/suspended counts, MRR (if billing tenants), recent signups, platform-wide booking/revenue stats, system health                                                                                                                                                                                                                                                                                                                                                                             |
| **Tenant Management**                                   | Create/edit/suspend/delete tenant, assign plan, set trial period, impersonate tenant admin (for support), view tenant's usage stats                                                                                                                                                                                                                                                                                                                                                                                    |
| **Super Admin User Management**                         | CRUD for platform-level staff, assign roles (e.g., Support, Billing)                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| **Role Management**                                     | Define what each super-admin-level role can access                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **Plans & Subscriptions** _(build now, activate later)_ | Since you confirmed billing isn't needed yet but is planned: build the `subscription_plans` / `tenant_subscriptions` tables and a basic "assign plan to tenant" UI now, with feature-limit fields (e.g., max staff, max bookings/month) already wired into tenant checks. Leave actual payment collection (Stripe/PayPal for SaaS billing) as a Phase-2 toggle — this avoids a painful schema migration later.                                                                                                         |
| **Global Reports**                                      | Cross-tenant analytics: top-performing tenants, total bookings/revenue across platform                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| **Email Template Builder**                              | Manages `platform_email_templates` — Super Admin's own templates, used only for Super-Admin-initiated campaigns. Audience is **tenants** (existing agencies/admins — announcements, feature updates) or **`saas_leads`** (prospects evaluating becoming a tenant). Does **not** create or touch any tenant's templates, and does **not** get an audience option to email tenants' end customers directly (see note below the schema — that's the tenant's own customer relationship to own, not yours to reach around) |
| **System Settings**                                     | Global email/SMS provider config, default currency list, plan/feature toggles, maintenance mode                                                                                                                                                                                                                                                                                                                                                                                                                        |
| **Audit Log Viewer**                                    | View activity logs across all tenants (for compliance/support)                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| **Support/Ticketing** _(optional, later)_               | If tenants can raise support requests to you                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |

### PANEL 2 — Tenant Panel (`/tenant`)

| Module                                     | Features                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Tenant Dashboard**                       | This tenant's leads pipeline, bookings this month, pending invoices, upcoming trips, staff performance snapshot                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| **Tenant Admin/User & Role Mgmt**          | Tenant owner creates staff accounts, assigns custom roles (Sales, Accountant, Ops) with granular permissions                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| **Lead Management**                        | Kanban or list view of leads by stage (New → Contacted → Negotiating → Won/Lost), source tracking, assign to staff, follow-up reminders, convert lead → booking                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| **Booking Management**                     | Create booking (from lead or direct), assign travelers (for group bookings), track itinerary/dates, status pipeline, link documents, link invoice                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| **Package & Itinerary Management**         | Tenant creates trip packages (e.g., "Everest Base Camp Trek"), writes the itinerary, sets base/sales pricing, marks package public/private. Tenant also maintains a **tenant-wide include/exclude catalog** (not fixed per package — since the same package can be sold at different tiers like luxury vs. normal); staff **check off which items apply per booking** at booking time. Customers/bookers get view-only access, never edit rights, at every stage                                                                                                                                                                                                                     |
| **Fixed Departure Management**             | For each package, tenant sets fixed departure date ranges with total slots **plus an optional overbooking buffer** (e.g., 20 slots + 2 buffer = bookable up to 22); status flips to "full" at capacity but stays bookable into the buffer, then "closed". Once truly full, the **same public booking form self-serves customers onto a waitlist** — no separate flow needed; staff see the queue and manually decide when/who to notify as slots free up                                                                                                                                                                                                                             |
| **Group Discount Rules**                   | Tenant sets pax-based discount tiers per package (or platform-wide). **Priority:** if staff manually sets a negotiated discount on a booking, it overrides the auto group discount (no stacking between the two). Promo codes and gift vouchers apply independently on top of whichever discount is active                                                                                                                                                                                                                                                                                                                                                                           |
| **Promo Codes & Gift Vouchers**            | Tenant creates promo codes (percent/flat, usage limits, validity window) and gift vouchers (fixed value, redeemable as payment credit) — **always scoped to that tenant only**, never redeemable across tenants — both stack on top of any existing discount                                                                                                                                                                                                                                                                                                                                                                                                                         |
| **Public Booking Website / Landing Pages** | Templated builder — tenant picks a theme and the page auto-generates from their published packages/fixed departures/branding (no custom code needed); an inquiry form on this page auto-creates a Lead with `origin = public_website`, feeding straight into the Lead pipeline                                                                                                                                                                                                                                                                                                                                                                                                       |
| **Add-on Services Catalog & Inventory**    | Tenant defines sellable extras (city tour, gear rental, insurance); for services with limited capacity (e.g., a city tour with 15 seats/day), tenant manages date-based slot inventory — bookings/purchases decrement available slots automatically                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| **Staff Management**                       | CRUD staff, designation, department, performance/assigned-leads count, deactivate staff                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| **Invoice & Billing**                      | Generate invoice from booking, itemized line items, tax/discount, PDF export/email to customer, mark paid/partial, overdue tracking                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| **Email Template Builder & Mass Emailing** | **Transactional templates** (forgot password, reset password, welcome email, booking confirmation, invoice sent, document approved/rejected, etc.) are **system-seeded per tenant automatically** — tenant/admin can only edit the subject/body content, not create new ones or change what triggers them. **Marketing templates** are fully tenant-created from scratch, saved, and reused across campaigns — completely isolated to that tenant, never visible to or shared with anyone else, including you as Super Admin. **Mass emailing** audience is restricted to the tenant's **own customers or own staff only** — never another tenant's data, never a platform-wide list |
| **Document Management**                    | Review/approve documents customers upload (passport, visa, photos)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| **Automated Reminders**                    | System tracks unsatisfied `booking_requirements` (missing documents, incomplete traveler info, unpaid balance) against the trip's start date, and auto-sends **email** reminders (SMS via Twilio / WhatsApp planned as a later channel) to **both staff and the customer** on a tenant-configurable schedule (e.g., 14/7/3 days before trip) until resolved                                                                                                                                                                                                                                                                                                                          |
| **Customer Management**                    | Tenant's own customer database (separate from Super Admin's tenant list) — CRM-lite: contact history, past bookings                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| **Reports**                                | Revenue by month, lead conversion rate, staff performance, booking status breakdown                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |

### PANEL 3 — Customer Portal (`/portal`)

| Module                                | Features                                                                                                                                                                                              |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Auth**                              | **Invite-only** — no public self-registration. Staff creates the booking, system sends an invite email/link, customer sets their own password on first login. Login, forgot/reset password after that |
| **Profile**                           | Update contact info, passport details, avatar                                                                                                                                                         |
| **My Bookings**                       | List of current & past bookings (past = read-only, cannot delete/edit)                                                                                                                                |
| **Booking Detail**                    | Itinerary, dates, **include/exclude list (view only — cannot edit)**, traveler list (if group/agency), notes/messages to tenant staff                                                                 |
| **Document Upload**                   | Upload passport/visa/photos per booking, see approval status                                                                                                                                          |
| **Add-on Services**                   | Browse tenant's available add-ons for their trip and request/purchase them                                                                                                                            |
| **Group/Agency View** _(conditional)_ | If customer type = agency or group leader → manage their group's traveler list & documents in bulk                                                                                                    |
| **Invoices & Payment**                | View invoice history (read-only), enter a promo code or redeem a gift voucher at checkout, pay outstanding invoices via PayPal (and HBL later), download invoice PDF                                  |
| **Notifications**                     | Booking status changes, document approval/rejection, payment reminders                                                                                                                                |

---

## 6. Key Business Flows

**Lead → Booking → Invoice → Payment lifecycle:**

```
Inquiry received (website/WhatsApp/referral)
   → Lead created (status: New) → assigned to a Sales Agent
   → Follow-up / negotiation (status: Contacted → Negotiating)
   → Deal closed → Lead converted to Booking (status: Confirmed)
   → Customer account auto-created/invited → customer portal access granted
   → Staff/customer uploads required documents
   → Invoice generated from booking → sent to customer
   → Customer pays (PayPal/HBL/bank/cash) → Invoice marked Paid
   → Customer may add on services any time before/during trip
   → Trip completed → Booking status: Completed → becomes read-only history
```

**Group/Agency booking nuance:** a `booking` can have many `booking_travelers`.
If `customer.type = agency`, the portal shows a "manage guests" view where they
bulk-add traveler rows and upload documents per traveler, not just per booking.

**Public website → Lead flow:**

```
Visitor browses tenant's public page → sees published Packages
   with open Fixed Departures (dates + remaining slots)
   → submits inquiry form → Lead auto-created (origin = public_website,
     package_id + fixed_departure_id attached if selected)
   → Lead lands in Tenant's normal Lead pipeline, assigned to a Sales Agent
   → same lead → booking → invoice → payment flow as before
```

**Fixed departure + discount at booking time:**

```
Staff/system creates Booking against a fixed_departure
   → booked_slots increments; if booked_slots >= total_slots, status = "full"
     but still bookable until booked_slots >= total_slots + overbooking_buffer
   → discount resolution: manual staff discount (if set) OR group discount
     tier (by pax count) — never both; promo code and/or gift voucher
     applied independently on top
   → booking_requirements checklist generated (documents, traveler info,
     payment) with due dates tied to the trip's start_date
```

**Reminder flow:**

```
Scheduled job checks all bookings with an upcoming start_date
   → for each unsatisfied booking_requirement, checks tenant's reminder
     schedule (e.g., 14/7/3 days before trip)
   → sends an email reminder to staff (internal nudge) AND the customer
     ("please upload your passport" / "balance due")
   → stops once the requirement is marked satisfied
```

**Waitlist flow (once a departure is truly full, past the overbooking buffer):**

```
Customer fills the SAME public booking form for a full fixed_departure
   → system detects capacity reached → entry saved to booking_waitlist
     (status: waiting, ordered by position) instead of creating a booking
   → staff see the waitlist queue on the departure's tenant-panel page
   → a cancellation frees a slot (or staff manually reviews the queue)
   → staff MANUALLY selects who to notify (no auto-timer) → triggers an
     email → status: notified, notified_by_staff_id recorded
   → customer confirms → staff converts entry to a real booking (status:
     converted); if customer declines/doesn't respond, staff marks
     expired and moves to the next person
```

---

## 7. Non-Functional Essentials (don't skip these)

- **Data isolation testing:** write automated tests that assert Tenant A can
  never query Tenant B's data — this is the #1 SaaS security risk.
- **Impersonation with audit trail:** when you (Super Admin) impersonate a
  tenant for support, log it clearly.
- **Soft deletes** on customers/bookings/invoices — never hard-delete financial
  records.
- **Invoice numbering per tenant** (e.g., `TENANTCODE-2026-0001`) to avoid
  clashes and keep it tenant-branded.
- **File storage:** use S3-compatible storage (not local disk) once you have
  multiple tenants, for scalability and backup.
- **Queue everything slow:** PDF generation, emails, SMS notifications → Laravel
  queues (`database` driver is fine initially, move to Redis later).
- **Currency handling:** since this is travel, support multi-currency per tenant
  early (store amounts in minor units/cents, avoid float).
- **Timezone per tenant:** trip dates should respect the tenant's operating
  timezone.

---

## 8. Suggested Build Roadmap

**Phase 1 — Foundation (2–3 weeks)**

- Laravel + Filament setup, 3 panels scaffolded, auth guards
- Tenant model + `tenant_id` scoping middleware
- Spatie roles/permissions wired per panel
- Super Admin: Tenant CRUD, Super Admin user CRUD

**Phase 2 — Packages, Itinerary, Fixed Departures (2–3 weeks)**

- Package CRUD + day-by-day itinerary builder
- Fixed departure scheduling with slot tracking
- Group discount tier rules
- _(Start HBL gateway paperwork/API access request in parallel now — bank
  integrations have long approval cycles, don't wait until Phase 6)_

**Phase 3 — Tenant Core CRM (3–4 weeks)**

- Customer, Lead modules (with Kanban/status pipeline, `origin` tracking)
- Lead → Booking conversion flow (including from a fixed departure)
- Staff management + tenant-custom roles

**Phase 4 — Booking, Documents, Invoicing (3–4 weeks)**

- Booking module + travelers + add-on services with inventory/availability
- Auto-applied group discounts on invoice generation
- Document upload/approval
- Invoice generation + PDF export (USD only)

**Phase 5 — Public Booking Website (2–3 weeks)**

- Per-tenant public landing page showing published packages + open fixed
  departures
- Inquiry form → auto-creates Lead
- (Can run in parallel with Phase 4 if you have two dev tracks)

**Phase 6 — Customer Portal (2–3 weeks)**

- Invite-only account creation flow (email invite → set password)
- Profile, booking view (read-only history), document upload
- Add-on service request flow (respecting inventory limits)

**Phase 7 — Payments (2 weeks)**

- PayPal integration end-to-end
- HBL integration (build on the API access started in Phase 2)

**Phase 8 — Polish & Reports (2 weeks)**

- Dashboards/reports for all 3 panels
- Notifications (email/SMS) — booking status, document approval,
  slot-almost-full alerts
- Activity/audit logs
- Super Admin: plan/feature-limit enforcement (billing UI itself can stay off
  until you're ready to charge)

---

## 9. Decisions Locked In

| Question                           | Decision                                                                                                                                                                                                                                                              |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Super Admin bills tenants?         | Not yet — but `subscription_plans`/`tenant_subscriptions` tables and feature-limit fields are built now so activating billing later needs no schema migration                                                                                                         |
| Customer self-registration?        | No — invite-only, created after a booking exists                                                                                                                                                                                                                      |
| Public booking website per tenant? | Yes — feeds Leads automatically via `origin = public_website`                                                                                                                                                                                                         |
| Multi-currency?                    | No — USD only for now (amounts still stored in cents/minor units for safety, even single-currency)                                                                                                                                                                    |
| Add-on service inventory?          | Yes — date-based slot tracking via `service_availability`                                                                                                                                                                                                             |
| Itinerary & group discounts?       | Tenant-managed — itinerary lives as a field on `packages` itself, discounts via `group_discount_tiers`                                                                                                                                                                |
| Fixed departures?                  | Tenant-managed per package, via `fixed_departures`, with auto slot tracking and auto-close when full                                                                                                                                                                  |
| Public site — builder vs custom?   | **Templated builder** — tenant picks a theme, page auto-generates from package data                                                                                                                                                                                   |
| Overbooking policy?                | **Allowed**, via a tenant-configurable `overbooking_buffer` per fixed departure, **plus a self-service waitlist** once truly full — customers join via the same public booking form; staff manually manage the queue and decide when to notify (no auto-expiry timer) |
| Discount stacking?                 | Manual staff discount overrides group discount (no stacking between those two); promo codes and gift vouchers always stack on top                                                                                                                                     |
| Promo code scope?                  | **Tenant-scoped only** — never redeemable across tenants                                                                                                                                                                                                              |
| Document/data reminders?           | **Yes** — automatic **email** reminders to both staff and customer when required booking data or documents are missing, on a tenant-configurable schedule; SMS (Twilio) or WhatsApp planned as a later channel                                                        |
| Waitlist claim process?            | **Manual** — staff/admin decide when to notify the next person and trigger the email themselves, no automatic countdown                                                                                                                                               |
| Waitlist entry point?              | **Self-service** on the public site, via the same booking form used for open departures                                                                                                                                                                               |

Everything is locked in.

## 10. Features Worth Considering (you didn't ask, but a travel SaaS usually needs these)

These aren't decided or added to the schema yet — just flagging real gaps I'd
expect a travel agency to hit within their first few months of using this, so
you can decide now which are V1 vs. later:

| Feature                                                                                          | Why it matters                                                                                                                                                                                        | Rough schema impact                                                                                                                                                                                       |
| ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Cancellation & refund policy**                                                                 | Nearly every travel booking eventually gets cancelled — you'll want tenant-defined rules like "100% refund 30+ days out, 50% within 14 days, 0% within 7 days," not a manual judgment call every time | `cancellation_policies` (tenant/package-scoped tiers by days-before-departure) + a `cancelled_reason`/`refund_amount` on `bookings`                                                                       |
| **Installment / partial payments**                                                               | Big trips (multi-week treks) are rarely paid in one shot — customers usually pay a deposit, then balance before departure                                                                             | You already have `invoices.status = partially_paid` — worth adding a `payment_schedule` (deposit %, balance due date) per booking so staff/customers see a clear plan, not just an ad-hoc partial payment |
| **Passport/visa expiry check**                                                                   | A common travel-ops failure mode: a customer's passport expires mid-trip or has <6 months validity, discovered too late                                                                               | Simple: add `passport_expiry` to `booking_travelers`, flag in the reminder job if expiry is within X months of `end_date`                                                                                 |
| **Staff commission tracking**                                                                    | If sales agents earn commission per booking, you'll want it visible without a spreadsheet on the side                                                                                                 | `commission_rate` on `tenant_users` or per-booking override, computed against `total_amount`                                                                                                              |
| **Guide/driver/vehicle assignment**                                                              | Once a departure is confirmed, tenants usually need to assign a lead guide and sometimes a driver/vehicle to it — an operations, not sales, concern                                                   | `departure_staff_assignments` (fixed_departure_id, tenant_user_id, role: guide/driver/coordinator)                                                                                                        |
| **Post-trip reviews/testimonials**                                                               | Feeds directly back into your public website (social proof on package pages) — cheap to add, meaningfully improves conversion                                                                         | `reviews` (booking_id, customer_id, rating, comment, is_public)                                                                                                                                           |
| **Terms & conditions acceptance**                                                                | Legal/liability protection — customer should explicitly accept terms at booking/payment time, with a timestamp on record                                                                              | `accepted_at`/`terms_version` on `bookings` or `invoices`                                                                                                                                                 |
| **Tenant email/branding customization** _(now implemented — see `email_templates` in Section 4)_ | Every email a customer gets (invite, invoice, reminder) should look like it's from the _tenant_, not your platform                                                                                    | Done via the email template builder, plus consider adding `email_from_name`/logo fields on `tenants` so the "From" header itself is branded, not just the body                                            |
| **API/webhook layer**                                                                            | Eventually a tenant will want to connect this to WhatsApp Business, Zapier, or their own website's contact form instead of your templated builder                                                     | Standard Laravel Sanctum API tokens + outbound webhooks per tenant — not urgent, but worth knowing you'll want it                                                                                         |
| **2FA for staff/admin logins**                                                                   | You're storing passport numbers and payment data — worth having TOTP 2FA available for `tenant_users` and especially `super_admins` from day one, not bolted on later                                 | Filament has first-party 2FA support — cheap to enable early                                                                                                                                              |

None of these need to be in Phase 1 — I'd prioritize **cancellation policy** and
**payment schedule/installments** highest, since those two touch money and will
come up almost immediately once you have real customers. The rest can wait until
you see which ones your actual tenants ask for. Let me know which (if any) you
want folded into the core schema now versus left for later.

This spec is ready to take into Phase 1 build, or into a lower-level breakdown
(Filament resources, ER diagram) whenever you're ready.

---

## 11. Public Read-Only API (per tenant)

This is what your **templated public booking website** (Section 5, Public
Booking Website module) actually calls to render each tenant's page — and it
doubles as a real API if a tenant ever wants to pull this data into their own
custom site later. All endpoints below are **GET only, no auth required,
list/detail only** — nothing here can create, update, or delete anything.

**Tenant resolution:** every request is scoped to exactly one tenant, resolved
either from the subdomain/custom domain (via `public_lead_pages.custom_domain`)
or an explicit `?tenant=slug` query param if you're not using subdomains yet. No
tenant ever sees another tenant's data through this API — same isolation
discipline as everywhere else in the system.

| Endpoint                                              | Returns                                          | Scoping rule                                                                                                                                                                                                                        |
| ----------------------------------------------------- | ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/public/v1/packages`                         | List of packages                                 | Only `is_public = true` AND `status = published`                                                                                                                                                                                    |
| `GET /api/public/v1/packages/{slug}`                  | Single package detail (itinerary, base info)     | Same filter as above                                                                                                                                                                                                                |
| `GET /api/public/v1/packages/{slug}/fixed-departures` | Fixed departures for that package                | Only `status = open` (or `full`, so the site can show "Join Waitlist" instead of hiding it); each item includes remaining slots computed as `total_slots + overbooking_buffer - booked_slots`                                       |
| `GET /api/public/v1/include-exclude`                  | The tenant's full active include/exclude catalog | `is_active`-equivalent items only; since this catalog isn't package-scoped (per your last decision), it's shown as general "what's typically included" reference — actual per-booking selection is confirmed by staff after inquiry |
| `GET /api/public/v1/services`                         | List of active add-on services                   | Only `is_active = true`; includes `has_limited_availability` flag so the front-end knows whether to show "limited slots" messaging                                                                                                  |

**A few build notes:**

- **Cache these responses** (Redis, keyed by tenant + endpoint) — public
  package/departure listings don't change every second, and this is the traffic
  most likely to spike (marketing campaigns, social shares).
- **Rate-limit by IP** via Laravel's throttle middleware — it's unauthenticated,
  so it's the one part of your system most exposed to scraping/abuse.
- Build these as a **separate route group/controller namespace** (e.g.
  `routes/api-public.php`) from your tenant-panel and Filament routes, so it's
  obvious at a glance which endpoints are public-safe and which require auth —
  reduces the risk of accidentally exposing a staff-only field.
- Use Laravel **API Resources** (`PackageResource`, `FixedDepartureResource`,
  etc.) rather than returning raw models — this guarantees internal-only fields
  (`base_price`/cost, `created_by_staff_id`, etc.) never leak into a public
  response even if someone adds a column later and forgets to check.

---

## 12. Security, Performance & Database Optimization Review

You asked for a real review, so here's a critical pass over everything designed
so far — organized by risk, not by section, so the highest-impact items are
first.

### 🔴 Security — must-do before launch

| Area                                             | Risk                                                                                                                                  | Recommendation                                                                                                                                                                                                                                                                                                                      |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Tenant data isolation**                        | This is the #1 risk in any multi-tenant app — a single missed `WHERE tenant_id = ?` leaks one tenant's data to another                | Enforce scoping via an Eloquent **global scope** applied automatically to every tenant-scoped model (not per-query discipline), backed by **automated tests** that assert tenant A can never fetch tenant B's records through any resource, relation, or API endpoint. Do this before writing a single Filament resource, not after |
| **Filament resource authorization**              | Filament hides menu items for unauthorized roles, but a direct URL hit still reaches the controller unless a **Policy** is registered | Every Resource needs a Laravel Policy (`viewAny`, `view`, `create`, `update`, `delete`) wired to Spatie permissions — don't rely on navigation visibility alone                                                                                                                                                                     |
| **File uploads (passport/visa/documents)**       | Documents contain sensitive PII; naive uploads risk MIME spoofing, public exposure, or serving as malware vectors                     | Store on a **private disk** (S3 with no public ACL), validate real MIME type (not just extension), generate **short-lived signed URLs** for viewing/downloading, and scan uploads if volume justifies it                                                                                                                            |
| **PII at rest**                                  | `passport_no` is high-sensitivity PII sitting in plain columns                                                                        | Use Laravel's built-in **encrypted casts** on `passport_no` (and similar fields) so it's encrypted at rest, not just access-controlled                                                                                                                                                                                              |
| **Payment data**                                 | Never let this app touch raw card numbers                                                                                             | Use PayPal's/HBL's own **hosted checkout or tokenization** — the app should never see a PAN. This keeps you out of PCI-DSS scope entirely, which is the correct choice at your size                                                                                                                                                 |
| **Payment webhooks**                             | A forged "payment succeeded" callback would let anyone mark an invoice paid for free                                                  | **Verify the signature** on every incoming webhook (PayPal IPN verification, HBL's provided signing scheme) before trusting the payload — never trust status from a redirect URL alone                                                                                                                                              |
| **Custom domain tenant resolution**              | If a tenant can point a domain at your app without proof of ownership, another party could spoof it                                   | Require **DNS TXT record verification** before activating a `public_lead_pages.custom_domain`                                                                                                                                                                                                                                       |
| **Public inquiry/lead forms & waitlist sign-up** | Unauthenticated forms are a magnet for spam bots inflating your leads table                                                           | Add **honeypot fields + rate limiting per IP**, and consider a lightweight CAPTCHA (Cloudflare Turnstile is low-friction) before the form submits                                                                                                                                                                                   |
| **Mass emailing abuse**                          | A compromised tenant account could blast spam through your infrastructure, tanking your sender reputation for every tenant            | Enforce **per-tenant sending limits** tied to their plan, and set up proper SPF/DKIM/DMARC on your sending domain(s) — consider tenant-specific subdomains for sending so one tenant's reputation issue doesn't affect others                                                                                                       |
| **Login security**                               | Brute force / credential stuffing against 3 separate login surfaces                                                                   | Rate-limit login attempts (Laravel's default throttle is a start), enforce a real password policy, and add 2FA (already flagged in Section 10) — prioritize it for `super_admins` and `tenant_users` given the PII/payment exposure                                                                                                 |
| **Impersonation**                                | Super Admin impersonating a tenant for support is powerful and needs guardrails                                                       | Every impersonation session should be **logged with start/end timestamps**, visibly bannered in the UI so it's never silent, and require re-authentication to exit back to the Super Admin identity                                                                                                                                 |

### 🟡 Fast Rendering / Performance

| Area                                         | Issue                                                                                                                                | Recommendation                                                                                                                                                                                                                                                                 |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Public booking website**                   | This is your highest-traffic, most latency-sensitive surface (SEO + conversion depend on speed)                                      | Cache rendered package/departure listings in Redis (already noted in Section 11), and put the whole thing behind a **CDN** (Cloudflare) for edge caching + image delivery                                                                                                      |
| **Filament table/list views**                | Booking, lead, and invoice lists will commonly show related data (customer name, package, status) — a naive setup causes N+1 queries | Explicitly `->with([...])` eager-load relationships in every Filament Resource's `getEloquentQuery()` — don't rely on lazy loading, and consider enabling `Model::preventLazyLoading()` in non-production so N+1s throw loudly during development instead of silently shipping |
| **Dashboards (both Super Admin and Tenant)** | Revenue totals, booking counts, and cross-tenant analytics computed live on every page load get slow fast as data grows              | Precompute expensive aggregates via a **scheduled job into a summary/reporting table**, refreshed hourly or nightly rather than queried live — especially for Super Admin's cross-tenant reports                                                                               |
| **Heavy synchronous work**                   | PDF generation, email sending, and image processing block the request if done inline                                                 | Already flagged in Section 7 — queue everything; worth repeating as it's the single easiest performance win you'll implement                                                                                                                                                   |
| **Package/public search at scale**           | If the platform grows to many tenants with large catalogs, `LIKE`-based search gets slow                                             | Not needed at MVP, but keep **Laravel Scout + Meilisearch** in mind as a drop-in upgrade path rather than something to retrofit under pressure                                                                                                                                 |

### 🟢 Database Optimization

| Area                                               | Recommendation                                                                                                                                                                                                                                                                                                                                                      |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Indexing**                                       | Every tenant-scoped table needs `tenant_id` indexed — and more importantly, **composite indexes matching real query patterns**: `bookings (tenant_id, status, start_date)`, `leads (tenant_id, status, assigned_staff_id)`, `fixed_departures (package_id, status, start_date)`. Don't index everything blindly — index what your actual list/filter views query by |
| **Concurrency on `fixed_departures.booked_slots`** | This is a genuine race-condition risk: two customers booking the last slot simultaneously could both succeed and blow past even the overbooking buffer unexpectedly                                                                                                                                                                                                 |
| **Money columns**                                  | Confirm every money field (`total_amount`, `sales_price`, `base_price`, `discount_value`, invoice amounts) is **integer minor units (cents) or `DECIMAL`, never `FLOAT`** — floating point rounding errors in financial data cause real, hard-to-debug discrepancies                                                                                                |
| **Uniqueness constraints**                         | Enforce at the DB level, not just app validation: `promo_codes (tenant_id, code)` unique, `invoices (tenant_id, invoice_no)` unique, `email_template_types.key` unique                                                                                                                                                                                              |
| **Soft deletes on hot tables**                     | You already have soft deletes on financial records (good) — on high-volume tables like `bookings` or `activity_logs`, consider a **partial/filtered index** (Postgres supports this natively; MySQL needs a workaround) on `deleted_at IS NULL` so active-row queries stay fast as soft-deleted rows accumulate                                                     |
| **Growth/archiving strategy**                      | Not needed at MVP, but `activity_logs`, `reminders`, and `email_campaign_recipients` grow unbounded and fastest of any tables here                                                                                                                                                                                                                                  |

### What I'd genuinely prioritize before writing code

If you only do five things from this list before Phase 1: **(1)** the
tenant-isolation global scope + automated leak tests, **(2)** Filament Policies
on every resource, **(3)** encrypted casts on `passport_no`, **(4)**
`lockForUpdate()` on fixed-departure booking, **(5)** money fields as
integers/DECIMAL. Everything else here matters, but those five are the ones that
are expensive to retrofit once real tenant data exists — the rest can be
tightened incrementally after launch.
