# Subscription — As-Built Design

> **Capability codes:** SUB-LM-01 (master), SUB-WF-FRAMEWORK-01, SUB-WF-* (operations),
> SUB-WF-RESTRICT-01 · **Module path:** `Modules/Subscription` · **Tests:**
> `Modules/Subscription/tests/Feature/*`

## 1. Purpose & boundaries
- **Owns:** the **subscription master row** (`subscription`) — the single writer of `status_code` and
  the cycle anchor — and the **operation ledger** (`subscription_operation`).
- **Does NOT own:** money (Billing), network (Provisioning), field work (WorkOrder), catalog (Catalog).
  It **orchestrates** them via workflow + events.
- **Job:** turn a lifecycle command into a governed, idempotent, single-in-flight operation whose steps
  are config-defined.

## 📖 Scenarios (service + Foundation involvement)

### 1. Pause an active subscription
`POST /api/subscriptions/sub_123/pause {reason_code:'CUSTOMER_TRAVEL'}` → `OperationFramework::trigger
('PAUSE')`: single-in-flight check, write `subscription_operation` (`INITIATED`), start `sub-pause`.
Workflow: `EnterPendingStatusHandler` flips `PENDING_PAUSE`; `PauseHandler` commits `PAUSED` +
`subscription_pause_history`. Emits `SubscriptionPaused`. *Status: ACTIVE→PENDING_PAUSE→PAUSED.*

### 2. Concurrency — second op rejected (409)
An `upgrade` while the pause runs (`final_state IS NULL`) → `trigger` throws `conflict(WAIT_FOR_OPERATION)`
→ **409**. (A `RESTRICT` op would be allowed — non-exclusive, R-SUB-WF-FW-2.)

### 3. Idempotent retry
Same `Idempotency-Key` → `trigger` returns the **original** operation — no second workflow.

### 4. Activate (full workflow, pay-first park)
`…/activate` → `trigger('ACTIVATE')` → `sub-activate`: `ValidateActivationHandler` →
`BillingIntentHandler` (if an activation fee is **pay-first**, parks `AWAITING_PAYMENT` on a
`ful-payment-received` catch until `InvoicePaid`) → `ActivateHandler` (`transitionStatus(ACTIVE)` +
`SubscriptionActivated`) → `FulfillmentCallHandler` (Provisioning broadcast). *Foundation: workflow +
outbox + pay-first parking.* *Proven by `SubscriptionApiTest`.*

### 5. Upgrade (mid-cycle MACD)
`…/upgrade {package_ref}` → `sub-upgrade`: `ValidatePackageChangeHandler` → `BillingIntentHandler`
(mid-cycle **proration** via the BillingIntent path) → `ChangePackageHandler` (swaps `package_ref`/
`package_version_id`) → `SubscriptionUpgraded`.

### 6. Terminate
`…/terminate` → `sub-terminate`: `EquipmentPickupHandler` (OSR) → `TerminateHandler`
(`transitionStatus(TERMINATED)` + `SubscriptionTerminated`, which Billing/Reporting consume).

### 7. Add a restriction (non-exclusive)
`POST …/{id}/restrictions {code:'OUTGOING_VOICE_BARRED'}` → `RestrictionService` (the one op that does
**not** change `status_code`): writes a `subscription_restriction`, broadcasts to Provisioning,
`SubscriptionRestrictionAdded`. Runs even with another op in flight (R-SUB-WF-FW-2).

### 8. Cancel an in-flight op → compensation
`POST /api/subscription-operations/{op}/cancel` → `OperationFramework::cancel`: cancels the running
`process_instance` and, if the master sits in a transient `PENDING_*`, **reverts** it to
`prior_subscription_status` (R-SUB-WF-FW-3). Emits `SubscriptionOperationCancelled`.

## 2. Data model — ≥4 sample rows + readings

### `subscription` (the master)
**Enum legend — `status_code`:** rest states `CREATED|PENDING_ACTIVATION|ACTIVE|PAUSED|SUSPENDED|
RESTRICTED|TERMINATED|RETIRED`; **transient** `PENDING_{PAUSE,RESUME,SUSPEND_NP,UPGRADE,DOWNGRADE,
RELOCATION,MIGRATION,TERMINATION}` (= an operation is mid-flight; cancel reverts to `prior`). Only
`ACTIVE` is billable. `billing_mode` = `POSTPAID`(invoice)|`PREPAID`(wallet); `cycle_model` =
`CALENDAR`|`ANNIVERSARY`.
```json
{ "subscription_id":"sub_123","package_ref":"pkg_triple","status_code":"ACTIVE","billing_mode":"POSTPAID","cycle_model":"ANNIVERSARY","current_cycle_end":"2026-07-01","cycle_period_days":30,"active_restrictions":[] }
{ "subscription_id":"sub_124","package_ref":"pkg_inet","status_code":"PENDING_PAUSE","current_transition_type":"PAUSE" }
{ "subscription_id":"sub_125","package_ref":"pkg_inet","status_code":"SUSPENDED","billing_mode":"PREPAID","suspended_at":"2026-06-10" }
{ "subscription_id":"sub_126","package_ref":"pkg_triple","status_code":"RESTRICTED","active_restrictions":["OUTGOING_VOICE_BARRED"] }
```
**Reading:** sub_123 is live & billable (POSTPAID → cycle close raises an invoice; a PREPAID one debits a
wallet and freezes on shortfall). sub_124 is **mid-pause** (transient → blocks a second op; cancel
reverts to ACTIVE). sub_125 was suspended for non-payment (BIL-04). sub_126 keeps service but bars
outgoing voice (`active_restrictions`). The main status is derived; `cycle_period_days`/`cycle_model`
drive proration + when the cycle closes.

### `subscription_operation` (per-command ledger)
**Enum legend — `operation_kind`:** `ACTIVATE|PAUSE|RESUME|TERMINATE|UPGRADE|DOWNGRADE|RELOCATE|MIGRATE|
SUSPEND_NP|RESTRICT` (RESTRICT = non-exclusive). **`current_state`:** `INITIATED→VALIDATING→
PENDING_STATE_FLIP→BILLING_CALL→AWAITING_PAYMENT→FULFILLMENT_CALL→AWAITING_FULFILLMENT_RESPONSE→
AWAITING_USER_TASK→COMMITTING_FINAL_STATE→EMITTING_EVENT` then terminal `COMPLETED|FAILED|CANCELLED`
(`REVERTING` on compensation). **`final_state`:** `NULL`=in-flight (the single-in-flight key).
```json
{ "operation_id":"op_1","subscription_id":"sub_124","operation_kind":"PAUSE","bpmn_process_key":"sub-pause","current_state":"AWAITING_PAYMENT","final_state":null,"prior_subscription_status":"ACTIVE","idempotency_key":"pause-sub_124-1" }
{ "operation_id":"op_2","subscription_id":"sub_123","operation_kind":"ACTIVATE","current_state":"COMPLETED","final_state":"COMPLETED","prior_subscription_status":"PENDING_ACTIVATION" }
{ "operation_id":"op_3","subscription_id":"sub_125","operation_kind":"SUSPEND_NP","current_state":"COMPLETED","final_state":"COMPLETED" }
{ "operation_id":"op_4","subscription_id":"sub_126","operation_kind":"RESTRICT","current_state":"COMPLETED","final_state":"COMPLETED" }
```
**Reading:** op_1 is **in-flight** (`final_state=null`) and parked at `AWAITING_PAYMENT` (a pause fee
is unpaid) — it blocks any other non-RESTRICT op on sub_124 and is the compensation source
(`prior=ACTIVE`). op_2 completed an activation. op_4 is a RESTRICT — it could have run concurrently with
another op (non-exclusive). The `idempotency_key` makes a retry return the same row.

### Config tables (no-code knobs)
```json
{ "subscription_operation_config":{ "operator_code":"WIK","operation_kind":"MIGRATE","enabled":false } }
{ "subscription_operation_config":{ "operator_code":"WIK","operation_kind":"PAUSE","enabled":true,"default_bpmn_process_key":"sub-pause-wik" } }
{ "subscription_pause_config":{ "operator_code":"WIK","max_pause_days":90,"pause_fee_required":true } }
{ "subscription_restriction":{ "subscription_id":"sub_126","code":"OUTGOING_VOICE_BARRED","status":"ACTIVE" } }
```
**Reading:** `subscription_operation_config` enables/disables a kind per operator or points it at a
**custom BPMN** (PAUSE → `sub-pause-wik`) — pure config. `MIGRATE` disabled → `trigger` rejects it. The
pause config sets the policy (max days, fee). Restrictions are the active partial-service bars.

## 3. Services
| Service | Responsibility | Key methods |
| --- | --- | --- |
| `SubscriptionService` | the **only writer** of the master | `create`, `setPendingStatus` (transient flip), `transitionStatus` (commit a rest state + timestamp + event) |
| `OperationFramework` | start + track every operation | `trigger` (idempotency + single-in-flight + process key + workflow + ledger), `cancel` (cancel instance + revert transient) |
| `RestrictionService` | partial-service restrictions (no `status_code` change) | non-exclusive |

## 4. API surface
| Method + path | Permission | Idempotent | →service |
| --- | --- | --- | --- |
| `POST /api/subscriptions` | `subscription.create` | yes | `SubscriptionService::create` |
| `POST …/{id}/activate` | `subscription.activate` | — | `trigger('ACTIVATE')` |
| `POST …/{id}/{pause\|resume\|terminate\|upgrade\|downgrade\|relocate\|migrate\|suspend-np}` | `subscription.manage` | — | `trigger(<KIND>)` |
| `POST/DELETE …/{id}/restrictions[/{code}]` | `subscription.manage` | yes | `RestrictionService` |
| `POST /api/subscription-operations/{op}/cancel` | `subscription.manage` | — | `OperationFramework::cancel` |

**Worked request — `OperationController@pause`:** `POST /api/subscriptions/sub_124/pause {reason_code}`
→ **202** `{operation_id, operation_kind:'PAUSE', current_state:'VALIDATING'}` (accepted; runs async).

## 5. Integration (events) — topic `subscription.lifecycle`
- **Emits:** `Subscription{Created,Activated,Suspended,SuspendedForNonPayment,Paused,Resumed,Terminated,
  StatusChanged}`, `Subscription{Upgraded,Downgraded,Relocated,Migrated}` (+`*Rejected`),
  `SubscriptionRestriction{Added,Removed}`, `SubscriptionOperation{Started,Completed,Failed,Cancelled}`.
- **Consumers:** Billing (cycle anchoring + `subscriptions_*` metrics), Reporting, Notification.
- **Consumes:** Billing `InvoicePaid` → `ConfirmBillingIntentOnPayment` confirms a pay-first intent +
  correlates `sub-payment-confirmed` to resume a parked operation.

## 6. Processes — with handler examples
- **Trigger:** `OperationFramework::trigger()` (process key from `subscription_operation_config`).
- **Reconcile:** `SyncOperationFromProcess` on `ProcessInstanceEnded` closes the ledger.
- **Handlers:** Validate{Operation,Activation,PackageChange,HomePassChange}, `EnterPendingStatusHandler`,
  Activate/Pause/Resume/Suspend/Terminate, Change{Package,HomePass}, `PutActiveRestrictionsHandler`,
  `FulfillmentCallHandler`, `BillingIntentHandler`, `EquipmentPickupHandler`, `CreateShiftingWoHandler`.

**`BillingIntentHandler` (topic `billing-intent`):** reads `{subscriptionId, operationKind}` + fee
config; calls `BillingIntentService::emit()`; pay-first → parks `AWAITING_PAYMENT`; outputs
`{intentConfirmed}`. **`ActivateHandler` (topic `activate`):** `transitionStatus(ACTIVE)` + emit; fail
`retryable:true`.

## 7. Policy & config
`subscription_operation_config` (enable/disable/custom BPMN per kind), per-operation config tables,
cycle cadence on the master.

## 8. Cross-module dependencies
- **Calls →** Provisioning (`FulfillmentCallHandler`), Billing (`BillingIntentHandler`), WorkOrder
  (`CreateShiftingWoHandler`).
- **Called by →** Fulfillment (`ACTIVATE`), Billing dunning (suspend-NP/restrict), ILM account sync.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-SUB-WF-FW-1 | ≤ 1 in-flight non-RESTRICT op per subscription | `trigger` + partial unique index on `final_state IS NULL` |
| R-SUB-WF-FW-2 | RESTRICT is non-exclusive | `trigger(exclusive:false)` |
| R-SUB-WF-FW-3 | cancel reverts a transient `PENDING_*` to `prior_subscription_status` | `cancel` |
| R-SUB-WF-FW-7 | process key is config-resolved; disabled kind rejected | `resolveProcessKey` |
| (single writer) | only `SubscriptionService` writes `status_code` | by construction |

## 10. Open items / deltas
- Some `subscription_upgrade_config`/`pause_config` fields are config-ahead-of-wiring (no gap today).
- Mid-cycle proration runs through the BillingIntent path, distinct from the cycle-fee proration in
  `Billing/ChargeComputeService` (see `billing.md`).
- SUB-WF flow ordering lives in `process_definition` data (the authored graph is the spec).
