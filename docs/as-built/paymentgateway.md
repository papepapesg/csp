# PaymentGateway — As-Built Design

> **Capability codes:** payment-rail ingress (M-Pesa / cards / bank) · **Module path:**
> `Modules/PaymentGateway` · **Test:** `GatewayCallbackTest`

## 1. Purpose & boundaries
- **Owns:** the **inbound callback** boundary — receive, de-duplicate, record provider payment
  notifications, resolve the billing account, then hand the money to Billing
  (`PaymentService::receiveAndApply`) and emit a `GatewayCallback*` audit event.
- **Does NOT own:** allocation/ledger or billing-mode routing (Billing `PaymentService` — POSTPAID→invoices,
  PREPAID→wallet top-up) or outbound initiation (a connector).
- **Job:** the safe, idempotent front door for money-in from external rails.

## 📖 Scenarios (service + Foundation involvement)

### 1. M-Pesa STK success lands
`POST /api/payment-gateway/mpesa/callbacks` → `GatewayCallbackService::handle`: records a
`payment_gateway_callback` (`RECEIVED`), resolves the account (ILM `payment_account_number`), calls
Billing **`PaymentService::receiveAndApply`** which allocates it, then marks the callback `PROCESSED`
and emits **`GatewayCallbackProcessed`** (topic `paymentgateway.callback`). *Proven by
`GatewayCallbackTest::test_mpesa_callback_resolves_account_and_applies_payment`.*

### 2. Retried callback is ignored (dedup)
The provider re-posts the same `(provider, external_ref)` → dedup hit → the **existing** callback is
returned (no new row), only a `GatewayCallbackDuplicate` audit event fires; `receiveAndApply` is **not**
called again. *Foundation: idempotent ingress — never double-credit.* *Proven by
`GatewayCallbackTest::test_duplicate_callback_is_deduped`.*

### 3. Prepaid vs postpaid routing (Billing-owned)
`receiveAndApply` routes by billing mode — a POSTPAID account's payment is applied to invoices, a PREPAID
subscription's money is a wallet top-up (BIL-05). The gateway just hands the money over; it owns no
ledger. *Proven by `GatewayCallbackTest::test_prepaid_callback_tops_up_the_wallet_not_an_invoice`.*

### 4. Card PSP callback
`POST /api/payment-gateway/visa/callbacks` → same path, different provider parsing (adapter/config).

### 5. Bank-transfer notification
A bank feed posts a transfer → recorded + applied (method `BANK_TRANSFER`).

### 6. Account cannot be resolved → REJECTED
A callback whose `account_ref` resolves to no billing account → callback `REJECTED`
(`reject_reason=ACCOUNT_NOT_FOUND`), `GatewayCallbackRejected` emitted, no money applied. *Proven by
`GatewayCallbackTest::test_unresolvable_account_is_rejected`.*

### 7. Apply fails downstream → REJECTED
If `receiveAndApply` throws, the callback is recorded `REJECTED` with the exception message as
`reject_reason`; the raw payload is kept for audit.

### 8. Admin audits callbacks
`GET /api/payment-gateway/callbacks[/{id}]` lists/inspects raw + parsed notifications.

## 2. Data model — ≥4 **complete** sample rows + readings per table
> **Completeness:** each row lists **every domain column** (nullables shown as `null`); the string
> primary key shown is the real one and `created_at`/`updated_at` are omitted by convention.

### `payment_gateway_callback` · `provider`: `MPESA|VISA|BANK_TRANSFER` · `status`: `RECEIVED|PROCESSED|REJECTED|DUPLICATE`
```json
{ "callback_id":"pgcb_1","operator_code":"WIK","provider":"MPESA","external_ref":"QGR7Xk12","account_ref":"254700000001","resolved_account_id":"acct_50","amount":5000.00,"currency":"KES","raw":{"TransID":"QGR7Xk12","TransAmount":"5000"},"status":"PROCESSED","payment_id":"pay_91","reject_reason":null,"received_at":"2026-06-20T09:00:00Z" }
{ "callback_id":"pgcb_2","operator_code":"WIK","provider":"VISA","external_ref":"ch_88","account_ref":"UNKNOWN","resolved_account_id":null,"amount":2500.00,"currency":"KES","raw":{"id":"ch_88"},"status":"REJECTED","payment_id":null,"reject_reason":"ACCOUNT_NOT_FOUND","received_at":"2026-06-20T09:30:00Z" }
{ "callback_id":"pgcb_3","operator_code":"WIK","provider":"VISA","external_ref":"ch_99","account_ref":"254700000003","resolved_account_id":null,"amount":2500.00,"currency":"KES","raw":{"id":"ch_99","status":"declined"},"status":"REJECTED","payment_id":null,"reject_reason":"card declined","received_at":"2026-06-20T10:00:00Z" }
{ "callback_id":"pgcb_4","operator_code":"WIK","provider":"BANK_TRANSFER","external_ref":"bt_7781","account_ref":"PAYBILL-22","resolved_account_id":null,"amount":12000.00,"currency":"KES","raw":{"ref":"bt_7781"},"status":"RECEIVED","payment_id":null,"reject_reason":null,"received_at":"2026-06-21T08:00:00Z" }
```
**Reading:** `(provider, external_ref)` is the **dedup key** (a DB unique constraint) — a re-posted
callback never inserts a second row; the existing record is returned and only a `GatewayCallbackDuplicate`
event fires, so money is never double-credited (no persisted `DUPLICATE` row results from a retry).
`PROCESSED` is the only status that applied the payment (and so the only one that fills `payment_id` +
`resolved_account_id`); pgcb_2 could not be matched to an account (`REJECTED`, `ACCOUNT_NOT_FOUND`) and
pgcb_3 is a declined card (`REJECTED`, the apply threw — `reject_reason` is the exception message); pgcb_4
is a freshly landed bank transfer still `RECEIVED` (handler not yet run). The full provider body is kept
in `raw` for audit.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `GatewayCallbackService::handle` | ingest + dedupe a provider callback, resolve the account, call Billing `PaymentService::receiveAndApply`, emit a `GatewayCallback*` event |

## 4. API surface
`POST /api/payment-gateway/{provider}/callbacks` (webhook), `GET /api/payment-gateway/callbacks[/{id}]`.

## 5. Integration (events)
- **Emits:** `PaymentReceived` (consumed by Billing). **Consumes:** none (ingress edge).

## 6. Processes
Stateless ingress; no workflow.

## 7. Policy & config
Per-provider parsing/credentials are adapter/config at deployment (the connector seam).

## 8. Cross-module dependencies
- **Drives →** Billing (`PaymentService`). **Connector seam →** each rail (M-Pesa STK/paybill, card PSP,
  bank feed).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| dedupe | a provider reference is acted once | `GatewayCallbackService` |

## 10. Open items / deltas
- **Outbound** rails (refunds, M-Pesa B2C) + provider statement/reconciliation feeds are deployment
  connectors (not yet built — by design).
