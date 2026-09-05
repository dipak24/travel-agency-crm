---
paths:
  - 'app/Filament/**/Pages/List*.php'
---

# Pages

## ListRecords pages here never get a free header Create button — always override getHeaderActions()
Unlike Filament's own default expectation, a `ListRecords` subclass in this project does NOT automatically show a "Create" button in the header unless it explicitly overrides `getHeaderActions()` and returns `[CreateAction::make()]`. `ListBookings`, `ListBookingTravelers`, and `ListInvoices` were missing this override (found 2026-09-05 via `assertActionExists(CreateAction::class)` against a live List page, not by reading the code) — those three resources had a working `create` route but no way to reach it from the index page. Every other List page in the codebase (Payments, Customers, Leads, Roles, Staff, etc.) already follows the correct pattern. When adding a new resource's `ListX` page, always add:

```php
protected function getHeaderActions(): array
{
    return [CreateAction::make()];
}
```

`tests/Feature/TenantPanelPagesTest.php`'s "every tenant panel resource index page has a working create button" test guards against this regressing again — extend its `$resources` array when adding a new tenant resource.
