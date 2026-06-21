# PaymentGateway — As-Built Design

> **Capability codes:** payment-rail ingress (M-Pesa / cards / bank) · **Module path:**
> `Modules/PaymentGateway` · **Test:** `GatewayCallbackTest`

## 1. Purpose & boundaries
- **Owns:** the **inbound callback** boundary — receive, de-duplicate, record provider payment
  notifications, then hand a clean `PaymentReceived` to Billing.
- **Does NOT own:** allocation/ledger (Billing `PaymentService`) or outbound initiation (a connector).
- **Job:** the safe, idempotent front door for money-in from external rails.

## 📖 Scenarios (service + Foundation involvement)

### 1. M-Pesa STK success lands
`POST /api/payment-gateway/mpesa/callbacks` → `GatewayCallbackService`: records a
`payment_gateway_callback` (`PROCESSED`), emits **`PaymentReceived`** (topic `billing.money`) with
amount + reference. Billing's `PaymentService` then allocates it. *Proven by `GatewayCallbackTest`.*

### 2. Retried callback is ignored (dedup)
The provider re-posts the same result → dedup by provider reference → recorded `DUPLICATE`, **no second**
`PaymentReceived`. *Foundation: idempotent ingress — never double-credit.*

### 3. Failed payment callback
A failure result → callback `FAILED`, no `PaymentReceived` emitted (nothing to allocate).

### 4. Card PSP callback
`POST /api/payment-gateway/visa/callbacks` → same path, different provider parsing (adapter/config).

### 5. Bank-transfer notification
A bank feed posts a transfer → recorded + `PaymentReceived` (method `BANK_TRANSFER`).

### 6. Malformed callback
An unparseable body → recorded `IGNORED` with the raw payload kept for audit; no event.

### 7. Callback for an unknown reference
A `PaymentReceived` whose reference matches no open invoice → Billing posts it to overpayment/credit
(handled downstream, not here).

### 8. Admin audits callbacks
`GET /api/payment-gateway/callbacks[/{id}]` lists/inspects raw + parsed notifications.

## 2. Data model — ≥4 **complete** sample rows + readings per table
> **Completeness:** each row lists **every domain column** (nullables shown as `null`); the string
> primary key shown is the real one and `created_at`/`updated_at` are omitted by convention.

### `payment_gateway_callback` · `provider`: `MPESA|VISA|BANK_TRANSFER` · `status`: `RECEIVED|PROCESSED|REJECTED|DUPLICATE`
```json
{ "callback_id":"pgcb_1","operator_code":"WIK","provider":"MPESA","external_ref":"QGR7Xk12","account_ref":"254700000001","resolved_account_id":"acct_50","amount":5000.00,"currency":"KES","raw":{"TransID":"QGR7Xk12","TransAmount":"5000"},"status":"PROCESSED","payment_id":"pay_91","reject_reason":null,"received_at":"2026-06-20T09:00:00Z" }
{ "callback_id":"pgcb_2","operator_code":"WIK","provider":"MPESA","external_ref":"QGR7Xk12","account_ref":"254700000001","resolved_account_id":"acct_50","amount":5000.00,"currency":"KES","raw":{"TransID":"QGR7Xk12"},"status":"DUPLICATE","payment_id":null,"reject_reason":"duplicate external_ref","received_at":"2026-06-20T09:00:05Z" }
{ "callback_id":"pgcb_3","operator_code":"WIK","provider":"VISA","external_ref":"ch_99","account_ref":null,"resolved_account_id":null,"amount":2500.00,"currency":"KES","raw":{"id":"ch_99","status":"declined"},"status":"REJECTED","payment_id":null,"reject_reason":"CARD_DECLINED","received_at":"2026-06-20T10:00:00Z" }
{ "callback_id":"pgcb_4","operator_code":"WIK","provider":"BANK_TRANSFER","external_ref":"bt_7781","account_ref":"PAYBILL-22","resolved_account_id":null,"amount":12000.00,"currency":"KES","raw":{"ref":"bt_7781"},"status":"RECEIVED","payment_id":null,"reject_reason":null,"received_at":"2026-06-21T08:00:00Z" }
```
**Reading:** `(provider, external_ref)` is the **dedup key** (a DB unique constraint) — pgcb_2 is a retry
of pgcb_1, recorded `DUPLICATE` but **not** re-emitted, so money is never double-credited. `PROCESSED`
is the only status that emitted `PaymentReceived` (and so the only one that fills `payment_id`); pgcb_3
is a declined card (`REJECTED`, `reject_reason` set, no event); pgcb_4 is a freshly landed bank transfer
still `RECEIVED` (not yet resolved to an account — `resolved_account_id:null`). The full provider body is
kept in `raw` for audit.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `GatewayCallbackService` | ingest + dedupe a provider callback; emit `PaymentReceived` on success |

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
