# Travel SaaS

Multi-tenant travel-business management SaaS built with Laravel and Filament. It
provides a platform admin area, a travel-agency staff area, and a customer
portal with explicit authentication boundaries.

## Current Status

The repository currently contains the SaaS foundation and the complete planned
business schema:

- Shared-schema multi-tenancy with `tenant_id` isolation.
- Separate `super_admin`, `tenant`, and `customer` authentication guards.
- Filament panels at `/admin`, `/tenant`, and `/portal`.
- Tenants, tenant users, customers, subscription plans, and subscriptions.
- Packages, itinerary JSON, include/exclude catalog items, fixed departures,
  overbooking buffers, and group discount tiers.
- CRM and booking tables: `leads`, `bookings`, `booking_travelers`,
  `booking_documents`, `booking_addons`, and `booking_include_exclude`.
- Pricing and operations tables: `promo_codes`, `gift_vouchers`,
  `booking_waitlist`, `reminders`, and `services`.
- Billing tables: `invoices`, `invoice_items`, and `payments`.
- Public and communication tables: `public_lead_pages`, email template catalogs,
  campaigns, recipients, unsubscribes, and notifications.
- Platform tables: `saas_leads` and platform-owned email templates.
- Row-locked fixed-departure capacity reservations.
- Permission, activity-log, and media-library package scaffolding.

The database schema is intentionally created ahead of feature delivery. Business
services, policies, Filament resources, public API endpoints, and workflows are
implemented phase by phase below.

### Phase 1 execution log

- Foundation schema and separate authentication tables completed.
- `/admin`, `/tenant`, and `/portal` Filament panels use separate guards and
  panel-specific login routes.
- Tenant global scope, request tenant resolution, and cross-tenant isolation
  regression tests completed.
- Spatie Permission teams are enabled with `tenant_id` as the team key.
- Transactional tenant onboarding now creates the tenant owner, assigns the
  `Tenant Owner` role, and starts the selected subscription plan.
- Seeded development accounts and clean Docker migration/seeding verification
  completed.

Phase 1 and Phase 2 catalog delivery are complete. The tenant panel now supports
package, include/exclude, fixed departure, discount tier, service, and service
availability CRUD with tenant isolation.

### Phase 2 execution log

- Package CRUD is available in the `/tenant` panel, including structured
  itinerary editing and publication status controls.
- Include/exclude catalogs, fixed departures, discount tiers, services, and
  date-based service availability are available in the `/tenant` panel.
- Fixed departure capacity reservation remains transactional and honors
  overbooking buffers.

### Phase 3 execution log

- Tenant-isolated customer management is available in the `/tenant` panel,
  including portal account fields and customer types.
- Tenant-isolated lead management is available with origin tracking, status
  pipeline fields, follow-up dates, and staff assignment.
- Lead conversion creates a booking snapshot and marks the lead won
  transactionally, reserving fixed-departure capacity when selected.
- CRM regression tests cover cross-tenant isolation, conversion behavior,
  duplicate conversion, and cross-tenant conversion rejection.
- Tenant-isolated staff CRUD and tenant-custom staff role management are
  available in the `/tenant` panel, with role assignments scoped by the active
  tenant.
- Authorization policies now protect customer, lead, staff, and staff-role
  operations, including same-tenant checks and owner-only administration.
- Converted leads now have a tenant booking list and status workflow at
  `/tenant/bookings`; staff can launch conversion directly from a lead editor.
- Phase 3 is complete. Phase 4 will extend bookings with travelers, documents,
  add-ons, invoicing, and payment records.

## Tech Stack

- PHP 8.4
- Laravel 13.30.1
- Filament 5.7.8
- Livewire 4.4.3
- PostgreSQL 16
- Tailwind CSS 4.3.3 and Vite 8.2.2
- Pest 5.1.3 and PHPUnit 13.3.1
- Laravel Pint 1.30.5
- Docker Compose
- Laravel Boost 2.7.0
- Laravel Tinker 3.0.2
- Laravel Sail 1.67.0
- Laravel Pail 1.2.7
- Spatie Laravel Permission 8.3.0
- Spatie Laravel Activitylog 5.1.0
- Spatie Laravel Media Library 11.23.6
- Laravel Vite Plugin 3.2.0
- Axios 1.20.0
- Concurrently 10.0.5

## Requirements

For the Docker workflow, install:

- Docker Desktop with the Linux engine enabled.
- Git.

Node.js, PHP, Composer, and PostgreSQL are not required on the host when using
the Docker workflow.

## Start With Docker

1. Copy the environment template if `.env` does not exist:

   ```powershell
   Copy-Item .env.example .env
   ```

2. Start Docker Desktop.

3. Build the application image and start all services:

   ```powershell
   docker compose up -d --build
   ```

   This starts:

   - `app`: PHP 8.4 Apache application on `http://localhost:8000`.
   - `queue`: Laravel database queue worker.
   - `pgsql`: PostgreSQL 16 database.

   The app waits for PostgreSQL, runs migrations automatically, creates the
   storage link, and then starts Apache.

4. Check the services:

   ```powershell
   docker compose ps
   docker compose logs -f app
   ```

5. Open the application:

   - Main application: <http://localhost:8000>
   - Super Admin panel: <http://localhost:8000/admin>
   - Tenant panel: <http://localhost:8000/tenant>
   - Customer portal: <http://localhost:8000/portal>

## Development Commands

Run Laravel commands inside the application container:

```powershell
docker compose exec app php artisan migrate:status
docker compose exec app php artisan db:seed
docker compose exec app vendor/bin/pest --compact
docker compose exec app php artisan route:list
docker compose exec app vendor/bin/pint --dirty
```

`DatabaseSeeder` also invokes `PermissionSeeder`, which creates the
`super_admin`, tenant, and customer guard permissions and assigns tenant owner,
sales, operations, and portal roles for the seeded demo accounts.

View logs or stop the stack:

```powershell
docker compose logs -f
docker compose logs -f queue
docker compose stop
docker compose down
```

To rebuild after changing the Dockerfile or Composer/frontend dependencies:

```powershell
docker compose up -d --build
```

To remove the database and all named Docker volumes, then start from an empty
database, use this destructive command deliberately:

```powershell
docker compose down -v
docker compose up -d --build
```

## Environment

The Docker defaults are defined in `.env.example`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=travel_agency_crm
DB_USERNAME=travel_saas
DB_PASSWORD=travel_saas_password
```

Inside Docker, `DB_HOST` must be `pgsql`, not `localhost`. `localhost` refers to
the current container, not the PostgreSQL service.

Optional host port overrides can be supplied without changing the container
ports:

```dotenv
APP_PORT=8001
DB_FORWARD_PORT=5433
```

The development seed creates these accounts:

```text
Super Admin: admin@example.com / password
Tenant Staff: staff@example.com / password
Portal Customer: customer@example.com / password
```

Change development credentials before using the application with real data.

If `/tenant/login` reports valid seeded credentials as invalid, reseed the
development database with `docker compose exec app php artisan db:seed --force`.
Tenant authentication models extend Laravel's foundation authenticatable user
class so Filament and Spatie Permission authorization can render the tenant
dashboard after login.

## Project Structure

```text
app/
	Auth/                         Custom tenant-aware auth provider
	Http/Middleware/              Tenant request resolution
	Models/                       SaaS and travel domain models
	Models/Concerns/              Reusable tenant model behavior
	Models/Scopes/                Global tenant query scope
	Providers/Filament/           Admin, tenant, and portal panels
	Services/                     Domain services such as capacity handling
	Support/                      Request-scoped application services
database/
	factories/                    Test data factories
	migrations/                   Schema and package migrations
	seeders/                      Development seed data
docker/
	apache-vhost.conf             Apache public-document-root configuration
	entrypoint.sh                 Migration and container startup script
resources/                      Vite CSS and JavaScript sources
tests/                          Pest feature and unit tests
```

## Architecture Rules

- Use one shared PostgreSQL schema with a non-null `tenant_id` on every
  tenant-owned table.
- Never put `tenant_id` on `super_admins`.
- Keep `super_admin`, `tenant`, and `customer` guards separate.
- Use policies for authorization; hidden navigation is not authorization.
- Use integer minor units for money, never floating-point amounts.
- Encrypt passport and other sensitive personal data.
- Store sensitive booking documents on a private disk.
- Use transactions and `lockForUpdate()` for capacity changes.
- Verify payment webhook signatures before changing payment state.
- Use eager loading for relationship-heavy Filament tables.

## Database Schema Inventory

The schema follows the table definitions in `Travel saas architecture.md`.
Tenant-owned tables have a non-null `tenant_id` and are protected by the
application tenant scope. Platform-owned tables such as `super_admins`,
`subscription_plans`, `saas_leads`, and `platform_email_templates` do not have a
`tenant_id`.

### Migration naming convention

Migrations use Laravel's timestamp prefix followed by a precise table or bounded
domain name:

```text
YYYY_MM_DD_HHMMSS_create_leads_table.php
YYYY_MM_DD_HHMMSS_create_bookings_table.php
YYYY_MM_DD_HHMMSS_add_cancelled_reason_to_bookings_table.php
```

Use one migration per table for new work. Only tightly coupled tables created in
the same feature may share a migration, and the filename must name that bounded
domain. Do not use generic names such as `create_architecture_tables`. Never
rename an applied migration; create a new migration for later changes.

The initial development reset uses `create_travel_business_tables.php` for the
already-designed CRM, booking, pricing, billing, and communication schema.
Future changes must follow the precise naming convention above.

The main domain groups are:

| Group                | Tables                                                                                                                                                                     |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Identity and tenancy | `tenants`, `super_admins`, `tenant_users`, `customers`, `subscription_plans`, `tenant_subscriptions`                                                                       |
| Travel catalog       | `packages`, `include_excludes`, `fixed_departures`, `group_discount_tiers`, `services`                                                                                     |
| CRM and bookings     | `leads`, `bookings`, `booking_travelers`, `booking_documents`, `booking_addons`, `booking_include_exclude`, `booking_waitlist`                                             |
| Pricing and billing  | `promo_codes`, `gift_vouchers`, `invoices`, `invoice_items`, `payments`                                                                                                    |
| Public and reminders | `public_lead_pages`, `reminders`                                                                                                                                           |
| Email and platform   | `email_template_types`, `email_templates`, `platform_email_templates`, `saas_leads`, `email_campaigns`, `email_campaign_recipients`, `email_unsubscribes`, `notifications` |

Money is stored as integer minor units. Booking itinerary and flexible
configuration data use PostgreSQL JSON columns. Passport numbers are encrypted
at the model layer, and booking documents are represented by private file
metadata rather than public paths.

See [DELIVERY_ROADMAP.md](DELIVERY_ROADMAP.md) for the phased build plan and
current status. Each phase requires focused Pest coverage, cross-tenant
read/write regression tests for tenant-owned features, `vendor/bin/pint`, and
a clean PostgreSQL `migrate:fresh --seed` run in Docker before it is
considered complete.

## Coding Standards

- Follow Laravel 13 conventions and existing sibling-file patterns.
- Use PHP 8.4 type declarations and explicit return types.
- Use curly braces for all control structures.
- Use constructor property promotion where constructors are needed.
- Use Eloquent relationships and model scopes before raw SQL.
- Use Form Request classes for controller validation.
- Use Laravel generators for new models, migrations, services, and tests.
- Do not call `env()` outside configuration files.
- Keep changes focused and avoid unrelated refactors.
- Do not commit `.env`, secrets, or production credentials.

## Testing and Formatting

Run the focused test first when changing a feature, then run the complete suite:

```powershell
docker compose exec app vendor/bin/pest --compact --filter=TenantIsolationTest
docker compose exec app vendor/bin/pest --compact
docker compose exec app vendor/bin/pint --dirty
```

The foundation tests cover tenant isolation and the fixed-departure capacity
rules. Add regression coverage for cross-tenant reads and writes with every new
tenant-owned module.

## Troubleshooting

### Docker engine is unavailable

Start Docker Desktop and make sure it is using the Linux containers engine. Then
retry:

```powershell
docker compose up -d --build
```

### Port 8000 is already in use

Set another host port in `.env`:

```dotenv
APP_PORT=8001
```

Then recreate the app container:

```powershell
docker compose up -d --build
```

### Database connection errors

Check that `.env` uses `DB_HOST=pgsql` and inspect database health:

```powershell
docker compose ps
docker compose logs pgsql
```

### Inspect application errors

```powershell
docker compose logs -f app
docker compose exec app php artisan about
```
