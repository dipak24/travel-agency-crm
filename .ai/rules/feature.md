---
paths:
  - 'tests/Feature/**'
---

# Feature

## Filament's fillForm() testing helper is broken on this project's Filament 5.7.8 + Livewire 4.4.3 combo — use set() per field instead
`Livewire::test(SomePage::class)->fillForm([...])` silently fails to apply any of the given values on this project's exact dependency versions (filament/forms 5.7.8, livewire/livewire 4.4.3 — both released within days of each other as of 2026-09-04). Confirmed by direct debugging: right after `fillForm()` runs, `$test->get('data')` still shows every field at its mount-time default, not the values passed in. Filament's `fillFormDataForTesting()` (vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php) uses `data_set($this, $statePath, $value)` to mutate state directly; that mutation does not survive to the next `call()`/`set()` on this version combo.

Direct Livewire `set()` calls on the same component (e.g. `$test->set('data.name', $value)`) DO work correctly and were confirmed to persist. Route around the broken helper with a small local test helper that loops `set("data.{$key}", $value)` for each field instead of calling `fillForm()` — see `fillSuperAdminForm()` in tests/Feature/SuperAdminManagementTest.php. This still exercises the real component, its validation, and its save logic; only the mechanism for setting the initial form input is swapped.

Re-check this whenever filament/filament or livewire/livewire is upgraded — if fillForm() starts working again, the workaround can be dropped, but don't assume it works without re-verifying (add a quick dump of `$test->get('data')` right after fillForm() to confirm before trusting it in a new test).
