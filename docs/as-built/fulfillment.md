# Fulfillment — As-Built Design (the golden path)

> **Capability codes:** FUL-02 (order capture & journey), FUL-02-FRAMEWORK · **Module path:**
> `Modules/Fulfillment` · **Source-of-truth test:** `Modules/Fulfillment/tests/Feature/FulfillmentJourneyTest.php`

> **Read this module first as a new dev** — it orchestrates Subscription, WorkOrder, ILM (KYC) and
> Billing in one config-defined journey, so it's the best end-to-end tour of the platform.

## 1. Purpose & boundaries
- **Owns:** the **order** (`fulfillment_order`) and its step ledger — the orchestration root that turns
  "customer wants service" into an active subscription.
- **Does NOT own:** any of the artifacts it creates (subscription, WO, billing) — it triggers their
  owning modules and tracks progress. The journey is **config** (`ful-order-capture` process
  definition), not code.
- **Job:** capture → (deposit gate) → create subscription + install WO → await install → KYC gate →
  trigger activation → complete; with **compensation** on cancel.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `fulfillment_order` | the order: `status`, `subscription_id`, `work_order_id`, `process_instance_id`, `payment_ref` | idempotent capture; stores the artifacts it created (for compensation) |
| `fulfillment_order_step` | append-only step ledger | one row per journey step |

**Statuses:** `CAPTURED → AWAITING_PAYMENT? → (SUBSCRIPTION, WO) → AWAITING_INSTALL → AWAITING_KYC? →
ACTIVATING → COMPLETED` | `CANCELLED`.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `OrderCaptureService` | `capture()` (create order + start `ful-order-capture`), `complete()` (desk install-confirm → correlate), `confirmDepositPaid()`, `cancel()` (interrupt flow + **compensate**: cancel WO + terminate subscription) |

## 4. API surface
`POST /api/fulfillment-orders` (idempotent), `…/{id}/complete` (idempotent), `…/{id}/cancel`,
`GET …`. Writes `permission:fulfillment.manage`.

## 5. Integration (events) — topic `fulfillment.order`
- **Emits:** `OrderCaptured`, `OrderStepCompleted`, `OrderCompleted`, `OrderCancelled`.
- **Consumes:** `ResumeOrderOnInstallFinalized` (WorkOrderFinalized → resume), `ResumeOrderOnKycApproved`
  (CustomerKycApproved → resume), `CancelOrderOnKycRejected` (CustomerKycRejected → cancel+compensate).

## 6. Processes (the journey, as data)
- **Flow:** `ful-order-capture` (`FulfillmentFlowSeeder`) — a `process_definition` graph; extend it in
  the Studio, not in code.
- **Handlers (topics):** `ValidateOrderHandler`, `CreateSubscriptionHandler` (→ Subscription),
  `CreateInstallWoHandler` (→ WorkOrder), `DepositGateHandler` (messageCatch `ful-payment-received`),
  `KycGateHandler` (gateway → `ful-kyc-approved` catch), `TriggerActivationHandler` (→ Subscription
  `OperationFramework::trigger('ACTIVATE')`, **blocks on a FUL-03 fraud flag**), `CompleteOrderHandler`.
- **Resumes:** message catches woken by `WorkOrderFinalized` and `CustomerKycApproved`.

## 7. Policy & config
The flow graph itself; deposit-required + package come from the request/catalog. No bespoke rules.

## 8. Cross-module dependencies
- **Calls →** Subscription (create + activate), WorkOrder (install WO), ILM (`hasProvisioningBlockingFlag`
  at activation), Billing (indirectly via subscription activation/cycle).
- **Reacts to →** WorkOrder (install finalized), ILM (KYC approved/rejected).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| FUL-02 §1.8 | cancel interrupts the journey wherever it is, **and compensates** (cancel WO + terminate sub) | `OrderCaptureService::cancel` + `compensate` |
| R-ILM-F-4 | a provisioning-blocking account flag blocks activation | `TriggerActivationHandler` |
| (idempotency) | capture + complete are idempotent | route `idempotency` middleware |

## 10. Open items / deltas
- KYC-rejection now **cancels** the parked order (was previously a hang) — fixed.
- Deposit-refund-on-cancel is not modelled (compensation cancels WO + subscription; a paid deposit
  refund would be an added BIL-02-ADJ path if required).
