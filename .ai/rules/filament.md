---
paths:
  - 'app/Providers/Filament/**'
---

# Filament

## Panel::colors() closures run before auth — use a renderHook for per-tenant/per-user dynamic colors
`Panel::colors()` (and any other Panel config that gets pulled into `Panel::boot()`, e.g. `FilamentColor::register($this->getColors())`) is evaluated by the `SetUpPanel` middleware, which Filament auto-applies **before** the panel's own `->middleware()` stack (`StartSession`, `Authenticate`, etc.) ever runs. A closure passed to `->colors(fn () => [...])` that reads `auth('tenant')->user()` will therefore always see `null` — it silently falls back to whatever the closure's fallback branch produces, on every request, no matter who's logged in. This bit us for tenant brand colors (2026-09-05): the color never reflected the logged-in tenant despite looking like a correct dynamic closure.

`brandName()` / `brandLogo()` do NOT have this problem — they're plain `evaluate()` calls resolved lazily wherever a Blade view calls `Filament::getBrandName()`/`getBrandLogo()`, which happens during actual page render (after auth).

Fix used in `TenantPanelProvider`: keep `->colors([...])` as a **static** default (evaluated fine at early boot, just can't be dynamic), and add a `->renderHook(PanelsRenderHook::HEAD_END, fn () => ...)` that outputs a `<style>:root{--primary-500:...}</style>` block computed from the authenticated user's own tenant — render hook closures run inside the actual page render pass, safely after auth, and their `<style>` override wins the CSS cascade over the static defaults since it renders later in `<head>`. See `TenantPanelProvider::tenantBrandColorStyles()`.

Any future per-tenant/per-user dynamic **color** needs (not logo/name) in ANY panel (e.g. a Phase 9 customer portal) must use this render-hook pattern, not a `colors()` closure.

## Panel-wide full width is set via maxContentWidth(Width::Full), not per-page
Every Filament page defaults to a constrained `7xl`-wide container (`BasePage::$maxContentWidth` is `null`, and `layout/index.blade.php` falls back to `Width::SevenExtraLarge` when neither the page nor the panel sets one). Before 2026-09-05, only individual Create/Edit pages fixed this (via `App\Filament\Concerns\HasFullWidthForm`), so all 20 `ListRecords` index pages across every panel were still rendering narrow — a real, user-visible layout bug found by grepping the rendered HTML for `fi-width-*`, not by reading the code.

Fixed at the panel level instead of per-page: `AdminPanelProvider`, `TenantPanelProvider`, and `PortalPanelProvider` all now chain `->maxContentWidth(Width::Full)` in their `panel()` config, so every page in every panel (List, Create, Edit, custom pages, Dashboard) is full width by default. The per-page `HasFullWidthForm` trait on existing Create/Edit pages is now a redundant no-op (harmless, left in place) — don't bother stripping it, but there's no need to add it to new pages either; the panel default already covers them. `tests/Feature/TenantPanelPagesTest.php`'s "the tenant panel renders pages at full width" test guards the tenant panel default.
