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
Workflow: `EnterPendingStatusHandler` flips `PENDING_PAUSE`; `PauseHandler` commits `SUSPENDED` (with a
pause reason — pause is a SUSPENDED rest state, not a separate one) + `subscription_pause_history`. Emits
`SubscriptionPaused`. *Status: ACTIVE→PENDING_PAUSE→SUSPENDED.*

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

## 2. Data model — ≥4 **complete** sample rows + readings
> **Completeness:** each row lists **every domain column** (nullables shown as `null`). The surrogate
> primary key shown is the real one (a string business key, e.g. `subscription_id`); `created_at`/
> `updated_at` are omitted by convention.

### `subscription` (the master)
**Enum legend — `status_code`:** rest states `CREATED|PENDING_ACTIVATION|ACTIVE|SUSPENDED|RESTRICTED|
TERMINATED|RETIRED` (the `PAUSED` constant survives `@deprecated` — pause now resolves to `SUSPENDED`
with a pause reason); **transient** `PENDING_{PAUSE,RESUME,SUSPEND_NP,UPGRADE,DOWNGRADE,RELOCATION,
MIGRATION,TERMINATION}` (= an operation is mid-flight; cancel reverts to `prior`). Only
`ACTIVE` is billable. `billing_mode` = `POSTPAID`(invoice)|`PREPAID`(wallet); `cycle_model` =
`CALENDAR`|`ANNIVERSARY`. (`current_cycle_*`/`last_cycle_closed_window_end`/`next_cycle_charge_invoice_id`
added by the cycle-window migration.)
```json
{ "subscription_id":"sub_123","customer_id":"cust_50","account_id":"acc_1","operator_code":"WIK","homepass_id":"hp_1","previous_homepass_id":null,"package_ref":"pkg_triple","package_version_id":"pv_1","previous_package_ref":null,"previous_package_version_id":null,"status_code":"ACTIVE","billing_mode":"POSTPAID","currency":"KES","cycle_model":"ANNIVERSARY","cycle_anchor_day":1,"cycle_period_days":30,"cycle_frequency_months":1,"active_restrictions":[],"current_transition_type":null,"current_transition_reason_code":null,"last_failure":null,"activated_at":"2026-01-01T08:00:00Z","suspended_at":null,"resumed_at":null,"terminated_at":null,"last_status_changed_at":"2026-01-01T08:00:00Z","start_date":"2026-01-01","end_date":null,"created_by":"u_sales1","updated_by":"system","retired_at":null,"current_cycle_start":"2026-06-01","current_cycle_end":"2026-07-01","last_cycle_closed_window_end":"2026-06-01","next_cycle_charge_invoice_id":null }
{ "subscription_id":"sub_124","customer_id":"cust_50","account_id":"acc_1","operator_code":"WIK","homepass_id":"hp_1","previous_homepass_id":null,"package_ref":"pkg_inet","package_version_id":"pv_2","previous_package_ref":null,"previous_package_version_id":null,"status_code":"PENDING_PAUSE","billing_mode":"POSTPAID","currency":"KES","cycle_model":"CALENDAR","cycle_anchor_day":1,"cycle_period_days":30,"cycle_frequency_months":1,"active_restrictions":[],"current_transition_type":"PAUSE","current_transition_reason_code":"CUSTOMER_TRAVEL","last_failure":null,"activated_at":"2026-02-01T08:00:00Z","suspended_at":null,"resumed_at":null,"terminated_at":null,"last_status_changed_at":"2026-06-20T09:00:00Z","start_date":"2026-02-01","end_date":null,"created_by":"u_sales1","updated_by":"u_csr2","retired_at":null,"current_cycle_start":"2026-06-01","current_cycle_end":"2026-07-01","last_cycle_closed_window_end":"2026-06-01","next_cycle_charge_invoice_id":null }
{ "subscription_id":"sub_125","customer_id":"cust_51","account_id":"acc_2","operator_code":"WIK","homepass_id":"hp_7","previous_homepass_id":null,"package_ref":"pkg_inet","package_version_id":"pv_2","previous_package_ref":null,"previous_package_version_id":null,"status_code":"SUSPENDED","billing_mode":"PREPAID","currency":"KES","cycle_model":"CALENDAR","cycle_anchor_day":null,"cycle_period_days":30,"cycle_frequency_months":1,"active_restrictions":[],"current_transition_type":null,"current_transition_reason_code":"NON_PAYMENT","last_failure":null,"activated_at":"2026-03-01T08:00:00Z","suspended_at":"2026-06-10T00:00:00Z","resumed_at":null,"terminated_at":null,"last_status_changed_at":"2026-06-10T00:00:00Z","start_date":"2026-03-01","end_date":null,"created_by":"u_sales3","updated_by":"system","retired_at":null,"current_cycle_start":"2026-06-01","current_cycle_end":"2026-07-01","last_cycle_closed_window_end":"2026-06-01","next_cycle_charge_invoice_id":null }
{ "subscription_id":"sub_126","customer_id":"cust_52","account_id":"acc_3","operator_code":"WIK","homepass_id":"hp_9","previous_homepass_id":"hp_8","package_ref":"pkg_triple","package_version_id":"pv_1","previous_package_ref":null,"previous_package_version_id":null,"status_code":"RESTRICTED","billing_mode":"POSTPAID","currency":"KES","cycle_model":"ANNIVERSARY","cycle_anchor_day":15,"cycle_period_days":30,"cycle_frequency_months":1,"active_restrictions":["OUTGOING_VOICE_BARRED"],"current_transition_type":null,"current_transition_reason_code":null,"last_failure":{"code":"FULFILLMENT_TIMEOUT","at":"2026-05-02T11:00:00Z"},"activated_at":"2026-04-01T08:00:00Z","suspended_at":null,"resumed_at":null,"terminated_at":null,"last_status_changed_at":"2026-06-01T10:00:00Z","start_date":"2026-04-01","end_date":null,"created_by":"u_sales1","updated_by":"system","retired_at":null,"current_cycle_start":"2026-06-15","current_cycle_end":"2026-07-15","last_cycle_closed_window_end":"2026-06-15","next_cycle_charge_invoice_id":null }
```
**Reading:** sub_123 is live & billable (POSTPAID → cycle close raises an invoice; a PREPAID one debits a
wallet and freezes on shortfall). sub_124 is **mid-pause** (transient → blocks a second op; cancel
reverts to ACTIVE — `current_transition_type=PAUSE`). sub_125 was suspended for non-payment (BIL-04,
`suspended_at` set). sub_126 keeps service but bars outgoing voice (`active_restrictions`) and carries a
`last_failure` from an earlier op. The main status is derived; `cycle_period_days`/`cycle_model`/
`cycle_anchor_day` drive proration + the `current_cycle_*` window the close worker reads.

### `subscription_operation` (per-command ledger)
**Enum legend — `operation_kind`:** `ACTIVATE|PAUSE|RESUME|TERMINATE|UPGRADE|DOWNGRADE|RELOCATION|
MIGRATION|SUSPEND_NP|RESTRICT` (RESTRICT = non-exclusive; the kind persisted is `RELOCATION`/`MIGRATION`
even though the route paths are `…/relocate`/`…/migrate`). **`current_state`** (granular narration vocabulary): `INITIATED|VALIDATING|
PENDING_STATE_FLIP|BILLING_CALL|AWAITING_PAYMENT|FULFILLMENT_CALL|AWAITING_FULFILLMENT_RESPONSE|
AWAITING_USER_TASK|COMMITTING_FINAL_STATE|EMITTING_EVENT|REVERTING|COMPLETED|FAILED|CANCELLED` (the older
`PENDING`/`RUNNING` survive `@deprecated`). **`final_state`:** `NULL`=in-flight (the single-in-flight key)
else the resulting `status_code`, or `FAILED|CANCELLED`. (`cancel_actor_user_id` added by the operation-config migration alongside the partial
in-flight unique indexes.)
```json
{ "operation_id":"op_1","operator_code":"WIK","subscription_id":"sub_124","operation_kind":"PAUSE","bpmn_process_key":"sub-pause","bpmn_process_instance_id":"pi_5501","initiating_actor_user_id":"u_csr2","initiating_actor_role":"CSR","idempotency_key":"pause-sub_124-1","idempotency_request_hash":"9f2c…ab","correlation_id":"corr_124","prior_subscription_status":"ACTIVE","current_state":"VALIDATING","final_state":null,"failure_reason_code":null,"failure_reason_detail":null,"cancel_reason_code":null,"cancel_actor_user_id":null,"input":{"reasonCode":"CUSTOMER_TRAVEL"},"started_at":"2026-06-20T09:00:00Z","completed_at":null,"duration_ms":null }
{ "operation_id":"op_2","operator_code":"WIK","subscription_id":"sub_123","operation_kind":"ACTIVATE","bpmn_process_key":"sub-activate","bpmn_process_instance_id":"pi_4400","initiating_actor_user_id":"u_sales1","initiating_actor_role":"SALES","idempotency_key":"activate-sub_123-1","idempotency_request_hash":"1a0e…77","correlation_id":"corr_123","prior_subscription_status":"PENDING_ACTIVATION","current_state":"COMPLETED","final_state":"COMPLETED","failure_reason_code":null,"failure_reason_detail":null,"cancel_reason_code":null,"cancel_actor_user_id":null,"input":{"packageRef":"pkg_triple"},"started_at":"2026-01-01T07:59:00Z","completed_at":"2026-01-01T08:00:00Z","duration_ms":60000 }
{ "operation_id":"op_3","operator_code":"WIK","subscription_id":"sub_125","operation_kind":"SUSPEND_NP","bpmn_process_key":"sub-suspend-np","bpmn_process_instance_id":"pi_6600","initiating_actor_user_id":null,"initiating_actor_role":"SYSTEM","idempotency_key":"suspendnp-sub_125-1","idempotency_request_hash":"77be…01","correlation_id":"corr_dun_2","prior_subscription_status":"ACTIVE","current_state":"COMPLETED","final_state":"COMPLETED","failure_reason_code":null,"failure_reason_detail":null,"cancel_reason_code":null,"cancel_actor_user_id":null,"input":{"dunningLevel":3},"started_at":"2026-06-10T00:00:00Z","completed_at":"2026-06-10T00:00:02Z","duration_ms":2000 }
{ "operation_id":"op_4","operator_code":"WIK","subscription_id":"sub_126","operation_kind":"RESTRICT","bpmn_process_key":"sub-restrict","bpmn_process_instance_id":null,"initiating_actor_user_id":"u_csr1","initiating_actor_role":"CSR","idempotency_key":"restrict-sub_126-1","idempotency_request_hash":"c4d2…9a","correlation_id":"corr_126","prior_subscription_status":"ACTIVE","current_state":"COMPLETED","final_state":"COMPLETED","failure_reason_code":null,"failure_reason_detail":null,"cancel_reason_code":null,"cancel_actor_user_id":null,"input":{"code":"OUTGOING_VOICE_BARRED"},"started_at":"2026-06-01T10:00:00Z","completed_at":"2026-06-01T10:00:01Z","duration_ms":1000 }
```
**Reading:** op_1 is **in-flight** (`final_state=null`, `current_state=VALIDATING`) — it blocks any other
non-RESTRICT op on sub_124 and is the compensation source (`prior_subscription_status=ACTIVE`). op_2
completed an activation. op_3 is a system-driven non-payment suspension (no `initiating_actor_user_id`).
op_4 is a RESTRICT — it could have run concurrently with another op (non-exclusive). The
`idempotency_key`/`idempotency_request_hash` make a retry return the same row.

### Config tables (no-code knobs)
**`subscription_operation_config`** (composite PK `operator_code`+`operation_kind`) and
**`subscription_pause_config`** (PK `operator_code`) are one-row-per-operator(-kind) policy.
**`subscription_restriction`** is the restriction *catalog* (the activated bars, by `restriction_code`).
```json
{ "subscription_operation_config":{ "operator_code":"WIK","operation_kind":"MIGRATION","default_bpmn_process_key":"sub-migration","operation_timeout_seconds":90,"billing_call_timeout_seconds":30,"fulfillment_call_timeout_seconds":60,"feature_flags":null,"enabled":false,"updated_by":"u_admin" } }
{ "subscription_operation_config":{ "operator_code":"WIK","operation_kind":"PAUSE","default_bpmn_process_key":"sub-pause-wik","operation_timeout_seconds":120,"billing_call_timeout_seconds":30,"fulfillment_call_timeout_seconds":60,"feature_flags":{"requireFee":true},"enabled":true,"updated_by":"u_admin" } }
{ "subscription_pause_config":{ "operator_code":"WIK","customer_self_service_enabled":true,"scheduled_pause_enabled":true,"max_future_scheduled_resume_days":90,"min_pause_hours":24,"customer_notification_enabled":true,"updated_by":"u_admin" } }
{ "subscription_restriction":{ "restriction_id":"srest_voice","operator_code":"WIK","restriction_code":"OUTGOING_VOICE_BARRED","name":"Outgoing voice barred","fulfillment_action":"AAA_RESTRICT_OUTGOING_VOICE","customer_self_service_eligible":false,"admin_only":true,"is_active":true } }
```
**Reading:** `subscription_operation_config` enables/disables a kind per operator or points it at a
**custom BPMN** (PAUSE → `sub-pause-wik`) and sets the per-call timeouts/`feature_flags` — pure config.
`MIGRATION` disabled → `trigger` rejects it. `subscription_pause_config` sets the voluntary-pause policy
(self-service switch, scheduled-pause window, minimum duration, notify). `subscription_restriction`
catalogs each bar with the `fulfillment_action` FUL-04 broadcasts and who may apply it
(`customer_self_service_eligible`/`admin_only`).

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
