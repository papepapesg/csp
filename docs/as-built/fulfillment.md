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

## 📖 Scenarios — read these first

### Scenario A — the happy path (capture → live)
1. **Request:** `POST /api/fulfillment-orders`
   ```json
   { "customer_id":"cust_1","account_id":"acct_1","homepass_id":"hp_1","package_ref":"pkg_triple" }
   ```
   → order `CAPTURED`, `ful-order-capture` workflow started, HTTP `201`.
2. **Worker drains** (`sophix:workflow:work`): `CreateSubscriptionHandler` makes a
   `PENDING_ACTIVATION` subscription (stores `subscription_id` on the order) →
   `CreateInstallWoHandler` makes an `INSTALLATION` WO (stores `work_order_id`) → flow **parks** at
   `AWAITING_INSTALL` (a `ful-install-finalized` message catch).
3. **Tech finishes:** the WO is finalized → `WorkOrderFinalized` →
   `ResumeOrderOnInstallFinalized` correlates the message → flow resumes → `KycGateHandler` (customer
   is APPROVED) → `TriggerActivationHandler` calls Subscription `ACTIVATE` → `CompleteOrderHandler`.
4. **State:** order `COMPLETED`, subscription `ACTIVE`. Emits `OrderCompleted` + `SubscriptionActivated`.
- **Proven by:** `FulfillmentJourneyTest::test_install_wo_finalization_resumes_the_flow_automatically`.

### Scenario B — KYC is rejected (cancel + compensate)
1. Order reaches the KYC gate but the customer is `PENDING`, so it **parks** `AWAITING_KYC`.
2. KYC is finally **REJECTED** → `CustomerKycRejected` → `CancelOrderOnKycRejected` →
   `OrderCaptureService::cancel`: interrupts the workflow, **cancels the install WO**, and
   **terminates the half-built subscription** (compensation). Order → `CANCELLED`.
- **Proven by:** `FulfillmentJourneyTest::test_kyc_rejection_cancels_the_parked_order_and_compensates`.

### Scenario C — fraud flag blocks activation
- If `acct_1` carries a `FRAUD_SUSPECTED` flag (`affects_provisioning`), `TriggerActivationHandler`
  refuses to activate (R-ILM-F-4) and the order stays un-activated until the flag is cleared.

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
