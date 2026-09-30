---
paths:
  - 'app/Services/PaymentGateways/**'
---

# Payment Gateways

## HBL (2C2P PACO) outcomes come from the Inquiry API, never the redirect or webhook body
Decided 2026-09-30 after UAT testing. HblGateway records every checkout as a `pending` Payment (transaction_ref = PACO orderNo) and only changes it via syncOrder(), which asks PACO's `api/1.0/Inquiry/transactionList` (PaymentStatusInfo.PaymentStatus: A/S→completed, F→declined, C→cancelled, E→expired, V→voided, PCPS/I/P→pending). The browser return and the backendURL notification only say *which* order to re-check; a "success" redirect never marks a payment paid. PACO can't reach localhost, so without this nothing was ever recorded locally.
Traps: PACO responses carry an `nbf` a few seconds ahead of our clock — JoseCodec must keep its clock-drift allowance or every real response fails with "The JWT can not be used yet". Inquiry answers in PascalCase, prePaymentUi in camelCase (read fields case-insensitively). Return URLs may arrive as a cross-site POST: PostedReturnController 303s them to the GET route (CSRF-exempt in bootstrap/app.php). UAT credentials for the demo agency come from HBL_UAT_* in .env via HblUatPaymentGatewaySeeder — never commit keys.
