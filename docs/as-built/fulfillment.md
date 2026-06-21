# Fulfillment — As-Built Design (the golden path)

> **Capability codes:** FUL-02 (order capture & journey), FUL-02-FRAMEWORK · **Module path:**
> `Modules/Fulfillment` · **Test:** `FulfillmentJourneyTest`

> **Read this module first as a new dev** — it orchestrates Subscription, WorkOrder, ILM (KYC) and
> Billing in one **config-defined** journey: the best end-to-end tour of the platform.

## 1. Purpose & boundaries
- **Owns:** the **order** (`fulfillment_order`) + its step ledger — the orchestration root turning
  "customer wants service" into an active subscription.
- **Does NOT own:** the artifacts it creates (subscription, WO, billing) — it triggers their owners and
  tracks progress. The journey is **config** (`ful-order-capture` process definition), not code.
- **Job:** capture → (deposit gate) → create subscription + install WO → await install → KYC gate →
  trigger activation → complete; with **compensation** on cancel.

## 📖 Scenarios (service + Foundation involvement)

### 1. Happy path — capture to live
`POST /api/fulfillment-orders {customer_id,account_id,homepass_id,package_ref}` →
`OrderCaptureService::capture` (order `CAPTURED`, starts `ful-order-capture`, HTTP 201). The
`sophix:workflow:work` worker drains: `CreateSubscriptionHandler` (→ Subscription, stores
`subscription_id`) → `CreateInstallWoHandler` (→ WorkOrder, stores `work_order_id`) → parks
`AWAITING_INSTALL`. *Proven by `FulfillmentJourneyTest`.*

### 2. Deposit required → park AWAITING_PAYMENT → resume
With `deposit_required:true` and no `payment_ref`, `DepositGateHandler` parks the flow on a
`ful-payment-received` catch (`AWAITING_PAYMENT`) — **no install WO yet**. `confirmDepositPaid` correlates
the message → the flow proceeds to create the WO. *Foundation: workflow message catch.* *Proven by
`FulfillmentJourneyTest::test_deposit_required_order_parks_awaiting_payment_then_resumes_on_deposit`.*

### 3. Install finalized (event) resumes automatically
The tech finalizes the WO → `WorkOrderFinalized` (outbox) → `ResumeOrderOnInstallFinalized` correlates
`ful-install-finalized` → flow resumes to the KYC gate. *Foundation: outbox listener + message correlation.*

### 4. Desk completes the install (manual path)
`POST …/{id}/complete` → `OrderCaptureService::complete` correlates the same message (the desk
equivalent of the WO event). *Shows: two ways to resume the same catch.*

### 5. KYC gate parks, then approval resumes
`KycGateHandler`: customer `PENDING` → park `AWAITING_KYC`. Final KYC approval → `CustomerKycApproved`
→ `ResumeOrderOnKycApproved` → flow loops back through the gate (now APPROVED) → activation. *Proven by
`FulfillmentJourneyTest::test_activation_is_gated_on_customer_kyc`.*

### 6. KYC rejected → cancel + compensate
KYC `REJECTED` → `CustomerKycRejected` → `CancelOrderOnKycRejected` → `OrderCaptureService::cancel`:
interrupts the workflow, **cancels the install WO**, **terminates the half-built subscription**. Order
`CANCELLED`. *Proven by `FulfillmentJourneyTest::test_kyc_rejection_cancels_the_parked_order_and_compensates`.*

### 7. Fraud flag blocks activation
`TriggerActivationHandler` checks ILM `hasProvisioningBlockingFlag`; a `FRAUD_SUSPECTED` flag blocks
activation (R-ILM-F-4) — the order stays un-activated until cleared. *Proven by `FulfillmentJourneyTest`.*

### 8. Cancel mid-flight → compensate
`POST …/{id}/cancel` at any point → `cancel` interrupts the running `process_instance`, then
`compensate()` cancels a cancellable WO and terminates a non-terminated subscription (reverse creation
order). *Proven by `FulfillmentJourneyTest::test_cancelling_an_order_compensates_its_install_wo_and_subscription`.*

## 2. Data model — ≥4 sample rows + readings

### `fulfillment_order` · `status`: `CAPTURED|AWAITING_PAYMENT|AWAITING_INSTALL|AWAITING_KYC|ACTIVATING|COMPLETED|CANCELLED`
```json
{ "order_id":"order_1","status":"COMPLETED","package_ref":"pkg_triple","subscription_id":"sub_1","work_order_id":"wo_1","process_instance_id":"pi_1" }
{ "order_id":"order_2","status":"AWAITING_INSTALL","subscription_id":"sub_2","work_order_id":"wo_2" }
{ "order_id":"order_3","status":"AWAITING_KYC","subscription_id":"sub_3","work_order_id":"wo_3" }
{ "order_id":"order_4","status":"AWAITING_PAYMENT","payment_ref":null }
{ "order_id":"order_5","status":"CANCELLED","subscription_id":"sub_5","work_order_id":"wo_5" }
```
**Reading:** the status mirrors **where the journey is parked**. order_1 is live (sub ACTIVE). order_2
waits for the tech; order_3 cleared install but is held on KYC; order_4 hasn't paid its deposit (no
sub/WO yet — the deposit gate is *before* creation). order_5 was cancelled — its WO + sub were
compensated. The order **stores the artifacts it created** (`subscription_id`,`work_order_id`) so cancel
can undo them.

### `fulfillment_order_step` (append-only ledger)
```json
{ "id":"st_1","order_id":"order_1","step":"CAPTURE","status":"DONE" }
{ "id":"st_2","order_id":"order_1","step":"SUBSCRIPTION","status":"DONE","result":{"subscriptionId":"sub_1"} }
{ "id":"st_3","order_id":"order_1","step":"INSTALLATION","status":"DONE","result":{"workOrderId":"wo_1"} }
{ "id":"st_4","order_id":"order_1","step":"KYC","status":"DONE","result":{"kycStatus":"APPROVED"} }
```
**Reading:** each journey step appends a row with its `result` — the audit trail of *what the workflow
did and what it produced*. This is how you reconstruct an order's history without reading the engine.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `OrderCaptureService` | `capture()` (create + start flow), `complete()`/`confirmDepositPaid()` (correlate messages), `cancel()` (interrupt + `compensate()`), `recordStep()` |

## 4. API surface
`POST /api/fulfillment-orders` (idempotent), `…/{id}/complete` (idempotent), `…/{id}/cancel`, `GET …`.
`permission:fulfillment.manage`.

## 5. Integration (events) — topic `fulfillment.order`
- **Emits:** `OrderCaptured`, `OrderStepCompleted`, `OrderCompleted`, `OrderCancelled`.
- **Consumes:** `ResumeOrderOnInstallFinalized` (WorkOrderFinalized), `ResumeOrderOnKycApproved`
  (CustomerKycApproved), `CancelOrderOnKycRejected` (CustomerKycRejected).

## 6. Processes (the journey, as data)
- **Flow:** `ful-order-capture` (`FulfillmentFlowSeeder`) — extend in the Studio, not code.
- **Handlers:** `ValidateOrderHandler`, `CreateSubscriptionHandler`, `CreateInstallWoHandler`,
  `DepositGateHandler` (catch `ful-payment-received`), `KycGateHandler` (gateway → catch
  `ful-kyc-approved`), `TriggerActivationHandler` (→ Subscription ACTIVATE; FUL-03 flag block),
  `CompleteOrderHandler`.

## 7. Policy & config
The flow graph; deposit-required + package come from the request/catalog.

## 8. Cross-module dependencies
- **Calls →** Subscription (create + activate), WorkOrder (install WO), ILM (flag check at activation).
- **Reacts to →** WorkOrder (install finalized), ILM (KYC approved/rejected).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| FUL-02 §1.8 | cancel interrupts the journey **and compensates** (WO + sub) | `OrderCaptureService::cancel`+`compensate` |
| R-ILM-F-4 | a provisioning-blocking flag blocks activation | `TriggerActivationHandler` |
| idempotency | capture + complete are idempotent | route `idempotency` middleware |

## 10. Open items / deltas
- KYC-rejection now **cancels** the parked order (was a hang) — fixed.
- Deposit-refund-on-cancel isn't modelled (cancel undoes WO + subscription; a paid-deposit refund would
  be an added BIL-02-ADJ path).
