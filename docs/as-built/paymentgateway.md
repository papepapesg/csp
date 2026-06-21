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

## 2. Data model — ≥4 sample rows + readings

### `payment_gateway_callback` · `status`: `RECEIVED|PROCESSED|DUPLICATE|FAILED|IGNORED`
```json
{ "callback_id":"pgc_1","provider":"mpesa","provider_reference":"QGR7Xk12","amount":5000,"status":"PROCESSED" }
{ "callback_id":"pgc_2","provider":"mpesa","provider_reference":"QGR7Xk12","status":"DUPLICATE" }
{ "callback_id":"pgc_3","provider":"visa","provider_reference":"ch_99","amount":2500,"status":"FAILED" }
{ "callback_id":"pgc_4","provider":"mpesa","provider_reference":null,"status":"IGNORED" }
```
**Reading:** the `provider_reference` is the **dedup key** — pgc_2 is a retry of pgc_1, recorded but not
re-emitted. pgc_3 was a declined card (no event). pgc_4 was malformed (kept for audit, ignored).
`PROCESSED` is the only status that emitted `PaymentReceived`.

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
