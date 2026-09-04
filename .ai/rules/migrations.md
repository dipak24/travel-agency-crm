---
paths:
  - 'database/migrations/**'
---

# Migrations

## Pre-launch: edit the original create-table migration instead of adding a new one
This app has not shipped yet — no production database depends on migration history being append-only. Until the user says otherwise, when a column needs to be added/changed on a table, edit that table's original `create_*_table` migration directly (add the column in place) rather than writing a new `add_x_to_y_table` migration. Then run `php artisan migrate:fresh --seed` (in the app container — see .ai/rules/general.md) to rebuild.

This already happened once: a `phone` column was first added to `super_admins` via a separate `add_phone_to_super_admins_table` migration, then folded back into `create_super_admins_table` and the standalone migration deleted, per explicit user request (2026-09-04) — many small add-column migrations are hard to manage while the schema is still this fluid.

Once the app is deployed anywhere with real data (staging or prod), stop doing this and go back to normal additive migrations — editing a migration that has already run against a real database silently desyncs that database's schema from migration history.
