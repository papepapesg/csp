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

## 2. Data model — ≥4 **complete** sample rows + readings
> **Completeness:** each row lists **every domain column** (nullables shown as `null`). The string
> business key shown is the real primary key; `created_at`/`updated_at` are omitted by convention.

### `fulfillment_order` · `status`: `CAPTURED|AWAITING_PAYMENT|AWAITING_INSTALL|AWAITING_KYC|ACTIVATING|COMPLETED|CANCELLED` · `billing_mode`: `PREPAID|POSTPAID`
```json
{ "order_id":"order_1","operator_code":"WIK","customer_id":"cust_50","account_id":"acct_50","homepass_id":"hp_1","package_ref":"pkg_triple","package_version_id":"pkgv_3","billing_mode":"POSTPAID","status":"COMPLETED","current_step":"ACTIVATION","subscription_id":"sub_1","work_order_id":"wo_1","payment_ref":"pay_1","created_by":"u_desk1","completed_at":"2026-06-20T11:00:00Z","process_instance_id":"pi_1" }
{ "order_id":"order_2","operator_code":"WIK","customer_id":"cust_51","account_id":"acct_51","homepass_id":"hp_2","package_ref":"pkg_inet","package_version_id":"pkgv_2","billing_mode":"POSTPAID","status":"AWAITING_INSTALL","current_step":"INSTALL","subscription_id":"sub_2","work_order_id":"wo_2","payment_ref":null,"created_by":"u_desk1","completed_at":null,"process_instance_id":"pi_2" }
{ "order_id":"order_3","operator_code":"WIK","customer_id":"cust_52","account_id":"acct_52","homepass_id":"hp_3","package_ref":"pkg_inet","package_version_id":"pkgv_2","billing_mode":"POSTPAID","status":"AWAITING_KYC","current_step":"KYC","subscription_id":"sub_3","work_order_id":"wo_3","payment_ref":null,"created_by":"u_desk2","completed_at":null,"process_instance_id":"pi_3" }
{ "order_id":"order_4","operator_code":"WIK","customer_id":"cust_53","account_id":"acct_53","homepass_id":"hp_4","package_ref":"pkg_triple","package_version_id":"pkgv_3","billing_mode":"PREPAID","status":"AWAITING_PAYMENT","current_step":"PAYMENT","subscription_id":null,"work_order_id":null,"payment_ref":null,"created_by":"u_desk2","completed_at":null,"process_instance_id":"pi_4" }
{ "order_id":"order_5","operator_code":"WIK","customer_id":"cust_54","account_id":"acct_54","homepass_id":"hp_5","package_ref":"pkg_inet","package_version_id":null,"billing_mode":"POSTPAID","status":"CANCELLED","current_step":"KYC","subscription_id":"sub_5","work_order_id":"wo_5","payment_ref":null,"created_by":"u_desk1","completed_at":null,"process_instance_id":"pi_5" }
```
**Reading:** the status mirrors **where the journey is parked** (`current_step` is the live cursor).
order_1 is live (sub ACTIVE, `completed_at` stamped). order_2 waits for the tech; order_3 cleared
install but is held on KYC; order_4 hasn't paid its deposit (no `subscription_id`/`work_order_id` yet —
the deposit gate is *before* creation). order_5 was cancelled — its WO + sub were compensated. The order
**stores the artifacts it created** (`subscription_id`,`work_order_id`) so cancel can undo them, and
holds `process_instance_id` (the driving workflow), `package_version_id` (the pinned catalog version)
and `billing_mode`.

### `fulfillment_order_step` (append-only ledger) · `step`: `CAPTURE|VALIDATE|SUBSCRIPTION|DEPOSIT|PAYMENT|INSTALL|KYC|ACTIVATION` · `status`: `PENDING|DONE|FAILED`
```json
{ "id":"st_1","order_id":"order_1","step":"CAPTURE","status":"DONE","result":null,"completed_at":"2026-06-20T09:00:00Z" }
{ "id":"st_2","order_id":"order_1","step":"SUBSCRIPTION","status":"DONE","result":{"subscriptionId":"sub_1"},"completed_at":"2026-06-20T09:01:00Z" }
{ "id":"st_3","order_id":"order_1","step":"INSTALL","status":"DONE","result":{"workOrderId":"wo_1"},"completed_at":"2026-06-20T10:30:00Z" }
{ "id":"st_4","order_id":"order_1","step":"KYC","status":"DONE","result":{"kycStatus":"APPROVED"},"completed_at":"2026-06-20T10:55:00Z" }
```
**Reading:** each journey step appends a row with its `result` and `completed_at` — the audit trail of
*what the workflow did, what it produced, and when*. This is how you reconstruct an order's history
without reading the engine.

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
