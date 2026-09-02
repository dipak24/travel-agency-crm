---
name: Travel SaaS Architecture
description: "Project-specific architecture, security, and workflow rules for the multi-tenant travel SaaS."
applyTo: "**"
---

# Travel SaaS Project Rules

Use `Travel saas architecture.md` as the source of truth for this repository.
Implement the roadmap incrementally; do not build every phase in one change.

## Architecture

- Use one shared PostgreSQL database with explicit, non-null `tenant_id`
  columns on tenant-owned tables.
- Keep `super_admin`, `tenant`, and `customer` authentication boundaries
  separate, using `super_admins`, `tenant_users`, and `customers`.
- Keep Filament panels at `/admin`, `/tenant`, and `/portal` on separate guards.
- Never add `tenant_id` to `super_admins`; platform-owned data must remain
  structurally separate from tenant-owned data.
- Do not create separate `staff` or `customer_users` tables unless the
  architecture document is deliberately revised.
- Use the existing tenant context and global scope for tenant isolation. A
  super-admin cross-tenant query must explicitly bypass the scope.
- Store all monetary values as integer minor units and use UTC timestamps with
  the tenant timezone stored as configuration.
- Encrypt passport numbers and other sensitive personal data.

## Security

- Enforce tenant isolation in models, relationships, policies, Filament
  resources, jobs, commands, and public endpoints.
- Add regression tests for cross-tenant reads and writes for every new module.
- Use policies for authorization; navigation visibility is not authorization.
- Store booking documents on a private disk and never expose raw file paths.
- Use database transactions and `lockForUpdate()` for capacity changes.
- Verify payment webhook signatures before changing payment state. Never accept
  raw card data.

## Implementation Order

1. Foundation: models, migrations, guards, tenant scope, panels, seed data, and
   isolation tests.
2. Packages, fixed departures, and pricing rules.
3. Customers, leads, staff roles, and lead conversion.
4. Bookings, travelers, documents, services, invoices, and payments.
5. Public tenant pages and read-only API.
6. Customer portal, reminders, campaigns, reports, and platform billing.

## Laravel Standards

- Follow Laravel 13, Filament 5, Livewire 4, and PostgreSQL conventions.
- Read the relevant architecture section before changing schema or boundaries.
- Use Laravel generators for new models, migrations, services, and tests.
- Use explicit PHP types and return types, curly braces, and constructor
  property promotion where appropriate.
- Prefer Eloquent relationships and scopes over raw SQL.
- Use Form Requests for controller validation and policies for authorization.
- Do not call `env()` outside configuration files.
- Queue slow work such as email, PDFs, and image processing.

## Validation

- Use the relevant Boost skill and version-specific Laravel documentation when
  available.
- Run the narrowest affected Pest test first, then `php artisan test --compact`.
- Run `vendor/bin/pint --dirty` after PHP edits.
- Run `php artisan migrate:fresh --seed --env=testing` for schema or seeder
  changes.
- Confirm `/admin`, `/tenant`, and `/portal` routes and guards after panel work.
- Docker Compose is the supported runtime. The app connects to PostgreSQL using
  `DB_HOST=pgsql`, never `localhost`.