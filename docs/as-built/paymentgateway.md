# PaymentGateway — As-Built Design

> **Capability codes:** payment-rail ingress (M-Pesa / cards / bank) · **Module path:**
> `Modules/PaymentGateway` · **Source-of-truth test:** `GatewayCallbackTest`

## 1. Purpose & boundaries
- **Owns:** the **inbound callback** boundary — receiving, de-duplicating and recording provider
  payment notifications, then handing a clean `PaymentReceived` to Billing.
- **Does NOT own:** allocation/ledger (Billing `PaymentService`), nor outbound payment initiation
  (a connector/adapter at deployment).
- **Job:** be the safe, idempotent front door for money-in from external rails.

## 📖 Scenarios — read these first

### Scenario A — an M-Pesa STK callback lands
1. **Request:** `POST /api/payment-gateway/mpesa/callbacks` (the provider posts the result).
2. `GatewayCallbackService`: records a `payment_gateway_callback` row, **de-duplicates** by the
   provider reference (a retried callback is ignored), and on success emits **`PaymentReceived`**
   (topic `billing.money`) with the amount + reference.
3. Billing's `PaymentService` consumes it → allocates to the open invoice(s) (overpayment → credit),
   emits `PaymentApplied` / `InvoicePaid`; `InvoicePaid` can in turn confirm a pay-first billing
   intent and resume a parked subscription flow.
- **Proven by:** `GatewayCallbackTest`.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `payment_gateway_callback` | raw + parsed provider notification | dedup by provider reference; status recorded |

## 3. Services
| Service | Responsibility |
| --- | --- |
| `GatewayCallbackService` | ingest + dedupe a provider callback; emit `PaymentReceived` on success |

## 4. API surface
`POST /api/payment-gateway/{provider}/callbacks` (provider webhook), `GET /api/payment-gateway/callbacks[/{id}]`
(admin audit).

## 5. Integration (events)
- **Emits:** `PaymentReceived` (consumed by Billing).
- **Consumes:** none (it is an ingress edge).

## 6. Processes
Stateless ingress; no workflow.

## 7. Policy & config
Per-provider parsing/credentials are adapter/config at deployment (the connector seam).

## 8. Cross-module dependencies
- **Drives →** Billing (`PaymentService`).
- **Connector seam →** each rail (M-Pesa STK/paybill, card PSP, bank feed) plugs in here.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| dedupe | a provider reference is recorded/acted once | `GatewayCallbackService` |

## 10. Open items / deltas
- **Outbound** rails (refunds, M-Pesa B2C) + provider statement/reconciliation feeds are deployment
  connectors (not yet built — by design).
