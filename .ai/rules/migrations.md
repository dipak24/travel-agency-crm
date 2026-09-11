---
paths:
  - 'database/migrations/**'
---

# Migrations

## Pre-launch: edit the original create-table migration instead of adding a new one
This app has not shipped yet — no production database depends on migration history being append-only. Until the user says otherwise, when a column needs to be added/changed on a table, edit that table's original `create_*_table` migration directly (add the column in place) rather than writing a new `add_x_to_y_table` migration. Then run `php artisan migrate:fresh --seed` (in the app container — see .ai/rules/general.md) to rebuild.

This already happened once: a `phone` column was first added to `super_admins` via a separate `add_phone_to_super_admins_table` migration, then folded back into `create_super_admins_table` and the standalone migration deleted, per explicit user request (2026-09-04) — many small add-column migrations are hard to manage while the schema is still this fluid.

Once the app is deployed anywhere with real data (staging or prod), stop doing this and go back to normal additive migrations — editing a migration that has already run against a real database silently desyncs that database's schema from migration history.

## Use jsonb, not json, for any Postgres column a relationship query might SELECT DISTINCT over
Postgres's native `json` type has no equality operator, so any query with `->distinct()` touching a table with a `json` column throws `SQLSTATE[42883]: could not identify an equality operator for type json` — not caught by the test suite since tests run on SQLite, which has no such restriction. Hit this 2026-09-10: `packages.itinerary` was `$table->json(...)`, and Filament's `Select::relationship('packages', ...)->multiple()` (added to PromoCodeResource for package-scoped promo codes) runs a `SELECT DISTINCT packages.*` — broke `/tenant/promo-codes/create` in production (Postgres) while all local tests stayed green. Fixed by changing the migration to `$table->jsonb(...)`. Use `jsonb` (never `json`) for new JSON columns on this app's own tables, and if an existing `json` column is ever joined into a `->distinct()` query, convert it to `jsonb` first. Note: vendor-published migrations (activity_log, media) are out of scope for this — don't edit those.
