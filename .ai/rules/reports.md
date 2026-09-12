---
paths:
  - 'app/Filament/**/Widgets/**,app/Filament/**/Reports/**'
---

# Reports

## Custom Filament widgets are lazy-loaded by default — set $isLazy = false or a plain HTTP request/assertSee will never see their content
Filament\Support\Concerns\CanBeLazy (used by every Widget) defaults `protected static bool $isLazy = true`. A lazy widget's initial server-rendered HTML is just a placeholder — its real content (stats/chart/table) only arrives via a follow-up Livewire AJAX call the browser fires after mount. Filament's own AccountWidget/FilamentInfoWidget explicitly override this to `false`; anything you add under app/Filament/Widgets, app/Filament/Tenant/Widgets, or the Reports-only widgets does not, unless you say so.

Practical trap (hit 2026-09-12 building the dashboard/reports widgets): a `$this->actingAs(...)->get('/tenant')->assertSee('Bookings this month')`-style test gets a 200 but never finds the text, because that content was never in the response body — it's lazy. Two fixes, pick one deliberately: (1) set `protected static bool $isLazy = false;` on the widget (reasonable for cheap aggregate-query widgets like these, where an extra round-trip buys nothing), or (2) keep it lazy and test via a mechanism that actually triggers the lazy-load handshake (not a plain HTTP GET).

For asserting exact computed numbers (stat values, chart data), don't scrape rendered HTML at all — bare digits like "1" or "5" also turn up incidentally in asset version strings/wire ids, making assertSeeTextInOrder(['Label', '3']) an unreliable false-positive-prone check. Instead pull the widget's protected getStats()/getData() via `(new ReflectionMethod($widget, 'getStats'))->invoke($widget)` and assert on the real return value (Stat::getValue()/getDescription(), or the chart array) — see TenantDashboardWidgetsTest.php/AdminDashboardWidgetsTest.php.
