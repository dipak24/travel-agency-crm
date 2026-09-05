---
paths:
  - 'app/Filament/Tenant/Resources/**,app/Filament/Resources/TenantResource*'
---

# Resources Filament Resources

## Never pair required() with readOnly() on a field the model fills in its creating() hook
A Filament TextInput that is both `->required()` and `->readOnly()`, with nothing setting its state on create, fails Filament's own form validation before the record is ever saved — so an Eloquent `creating()` hook that would auto-fill the value (e.g. `Invoice::generateInvoiceNumber()`) never gets a chance to run. This silently blocked every tenant from creating an `Invoice` through `InvoiceResource` (real bug found 2026-09-05 by exercising `CreateInvoice` end-to-end in a test, not just unit-testing the model). Fix: `->hiddenOn('create')` + readOnly-only on edit, matching the working `TenantInvoiceResource` (admin panel) convention for its own `invoice_no` field. When adding a readOnly field backed by a model-side auto-generation hook, always hide it on create (or make it not required) rather than requiring it.
