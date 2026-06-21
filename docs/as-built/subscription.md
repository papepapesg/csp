# Subscription — As-Built Design

> **Capability codes:** SUB-LM-01 (master), SUB-WF-FRAMEWORK-01, SUB-WF-* (operations),
> SUB-WF-RESTRICT-01 · **Module path:** `Modules/Subscription`
> **Source-of-truth tests:** `Modules/Subscription/tests/Feature/*`

## 1. Purpose & boundaries
- **Owns:** the **subscription master row** (`subscription`) — the single writer of `status_code` and
  the cycle anchor — and the **operation ledger** (`subscription_operation`).
- **Does NOT own:** money (Billing), network (Provisioning), field work (WorkOrder), catalog (Catalog).
  It **orchestrates** them via workflow + events.
- **Job:** turn a lifecycle command into a governed, idempotent, single-in-flight operation whose
  steps are config-defined.

## 📖 Scenarios — read these first

### Scenario A — agent pauses an active subscription
1. **Request:** `POST /api/subscriptions/sub_123/pause` `{ "reason_code":"CUSTOMER_TRAVEL" }`
2. `OperationController@pause` → `OperationFramework::trigger($sub,'PAUSE',input)`: resolves the
   process key, passes the single-in-flight check, writes a `subscription_operation` (`INITIATED`),
   starts the `sub-pause` workflow.
3. Workflow: `EnterPendingStatusHandler` flips the master to **`PENDING_PAUSE`** (transient) →
   `PauseHandler` commits **`PAUSED`** + opens a `subscription_pause_history` row.
4. **Events:** `SubscriptionPaused` → Billing pauses dunning, Notification notifies the customer.
5. **State:** `status_code`: `ACTIVE → PENDING_PAUSE → PAUSED`; operation ledger closes `COMPLETED`.

### Scenario B — a second command while one is in flight → **409**
`OperationFramework::trigger` finds an in-flight non-RESTRICT op (`final_state IS NULL`) → throws
`conflict(nextAction: WAIT_FOR_OPERATION)`. (A `RESTRICT` op would be allowed — non-exclusive.)

### Scenario C — idempotent retry
Same `Idempotency-Key` returns the **original** operation — no second workflow, no double-pause.

> Proven by `Modules/Subscription/tests/Feature/*`.

## 2. Data model — sample rows + how to read them

### `subscription` (the master)
**Enum legend — `status_code`:**
| Value | Kind | Meaning / what it allows |
| --- | --- | --- |
| `CREATED` | rest | row exists, not yet activated |
| `PENDING_ACTIVATION` | rest | awaiting first activation |
| `ACTIVE` | rest | live & billable — the only state cycle-close charges |
| `PAUSED` | rest | voluntarily paused (dunning paused too) |
| `SUSPENDED` | rest | suspended for non-payment (BIL-04) |
| `RESTRICTED` | rest | partial-service barred (e.g. outgoing voice) |
| `PENDING_PAUSE` / `PENDING_RESUME` / `PENDING_SUSPEND_NP` / `PENDING_UPGRADE` / `…DOWNGRADE` / `…RELOCATION` / `…MIGRATION` / `…TERMINATION` | **transient** | an operation is **mid-flight**; the master is parked here and an open `subscription_operation` exists. `OperationFramework::cancel` reverts a transient back to `prior_subscription_status` |
| `TERMINATED` | terminal | ended; emits `SubscriptionTerminated` |
| `RETIRED` | terminal | archived |

**Other enums:** `billing_mode` = `POSTPAID` (raises an invoice) \| `PREPAID` (debits a wallet);
`cycle_model` = `CALENDAR` (month-aligned) \| `ANNIVERSARY` (signup-day aligned).

**Sample row 1 — a healthy postpaid triple-play:**
```json
{ "subscription_id":"sub_123", "account_id":"acct_1", "package_ref":"pkg_triple",
  "status_code":"ACTIVE", "billing_mode":"POSTPAID", "currency":"KES",
  "cycle_model":"ANNIVERSARY", "current_cycle_start":"2026-06-01",
  "current_cycle_end":"2026-07-01", "cycle_period_days":30, "active_restrictions":[] }
```
**Reading:** *sub_123 is live and billable. Because `billing_mode=POSTPAID`, its cycle close raises an
invoice (a PREPAID one would debit a wallet and freeze on shortfall). `ANNIVERSARY` + a 30-day
`cycle_period_days` means the next cycle close fires at `current_cycle_end` (1 Jul) and the fee
prorates only if the window is short. `active_restrictions:[]` = full service.*

**Sample row 2 — the same sub mid-pause (transient):**
```json
{ "subscription_id":"sub_123", "status_code":"PENDING_PAUSE",
  "current_transition_type":"PAUSE", "last_status_changed_at":"2026-06-20T10:00:00Z" }
```
**Reading:** *An operation is running right now. The master sits in `PENDING_PAUSE` (a transient
state), so a second non-RESTRICT command will be rejected (single-in-flight). If the operation is
cancelled, `OperationFramework::cancel` reverts this to the prior `ACTIVE`.*

### `subscription_operation` (the per-command ledger)
**Enum legend — `operation_kind`:** `ACTIVATE | PAUSE | RESUME | TERMINATE | UPGRADE | DOWNGRADE |
RELOCATE | MIGRATE | SUSPEND_NP | RESTRICT`. (`RESTRICT` is the only **non-exclusive** kind.)

**Enum legend — `current_state` (narrates progress, set by `SyncOperationFromProcess`):**
`INITIATED → VALIDATING → PENDING_STATE_FLIP → BILLING_CALL → AWAITING_PAYMENT → FULFILLMENT_CALL →
AWAITING_FULFILLMENT_RESPONSE → AWAITING_USER_TASK → COMMITTING_FINAL_STATE → EMITTING_EVENT`
then a terminal `COMPLETED | FAILED | CANCELLED` (`REVERTING` on a compensating exit).
**`final_state`:** `NULL` = **in-flight** (this is what the single-in-flight + DB partial-unique index
key on); else `COMPLETED|FAILED|CANCELLED`.

**Sample row — a pause parked at payment:**
```json
{ "operation_id":"op_88", "subscription_id":"sub_123", "operation_kind":"PAUSE",
  "bpmn_process_key":"sub-pause", "current_state":"AWAITING_PAYMENT", "final_state":null,
  "prior_subscription_status":"ACTIVE", "idempotency_key":"pause-sub_123-1",
  "input":{ "reason_code":"CUSTOMER_TRAVEL" } }
```
**Reading:** *op_88 is the in-flight pause for sub_123. `final_state=null` ⇒ it counts against the
single-in-flight rule (no other non-RESTRICT op may start). `current_state=AWAITING_PAYMENT` ⇒ the
workflow is parked on a `ful-payment-received` catch waiting for a pause fee to be paid. `prior_…
=ACTIVE` is the compensation target if this is cancelled. The `idempotency_key` makes a client
retry return this same row.*

### Config tables (no-code knobs)
`subscription_operation_config` (per-(operator,kind): `enabled`, `default_bpmn_process_key`),
`subscription_{pause,suspend_np,upgrade,restrict}_config`, `subscription_pause_history`
(append-only), `subscription_restriction` (active restrictions).

## 3. Services & responsibilities (with worked calls)
| Service | Responsibility |
| --- | --- |
| `SubscriptionService` | the **only writer** of the master row |
| `OperationFramework` | start + track every operation |
| `RestrictionService` | partial-service restrictions (the one op that doesn't change `status_code`) |

**`OperationFramework::trigger($sub, 'PAUSE', ['reason_code'=>'CUSTOMER_TRAVEL'], idempotencyKey)`**
→ ① same-key replay? return original. ② in-flight non-RESTRICT op? `409`. ③ resolve process key
from `subscription_operation_config` (else `sub-pause`). ④ in one tx: insert the operation
(`INITIATED`) + publish `SubscriptionOperationStarted`. ⑤ `WorkflowEngine::start('sub-pause', …)`.
→ returns the `SubscriptionOperation`.

**`SubscriptionService::transitionStatus($sub, Subscription::PAUSED, $extra, $eventType?)`** → commits
the master to a **rest** state, clears the transient marker, stamps the lifecycle timestamp
(`suspended_at` for PAUSED), and emits the status-specific event (`SubscriptionPaused`). It is the
**only** path that writes `status_code` to a rest state.

## 4. API surface (+ controller example)
| Method + path | Permission | Idempotent | →service |
| --- | --- | --- | --- |
| `POST /api/subscriptions` | `subscription.create` | yes | `SubscriptionService::create` |
| `POST /api/subscriptions/{id}/activate` | `subscription.activate` | — | `OperationFramework::trigger('ACTIVATE')` |
| `POST /api/subscriptions/{id}/{pause\|resume\|terminate\|upgrade\|downgrade\|relocate\|migrate\|suspend-np}` | `subscription.manage` | — | `OperationFramework::trigger(<KIND>)` |
| `POST/DELETE /api/subscriptions/{id}/restrictions[/{code}]` | `subscription.manage` | yes | `RestrictionService` |
| `POST /api/subscription-operations/{op}/cancel` | `subscription.manage` | — | `OperationFramework::cancel` |

**Worked request — `OperationController@pause`:**
```http
POST /api/subscriptions/sub_123/pause           →  202 { "operation_id":"op_88",
{ "reason_code":"CUSTOMER_TRAVEL" }                       "operation_kind":"PAUSE",
                                                          "current_state":"VALIDATING" }
```
The controller validates the body, resolves `{subscription}` by route-model binding, and calls
`OperationFramework::trigger`. **202** (accepted) — the work runs asynchronously in the workflow.

## 5. Integration (events) — topic `subscription.lifecycle`
- **Emits:** `Subscription{Created,Activated,Suspended,SuspendedForNonPayment,Paused,Resumed,
  Terminated,StatusChanged}`, `Subscription{Upgraded,Downgraded,Relocated,Migrated}` (+`*Rejected`),
  `SubscriptionRestriction{Added,Removed}`, `SubscriptionOperation{Started,Completed,Failed,Cancelled}`.
- **Consumers:** Billing (cycle anchoring, `subscriptions_*` metrics), Reporting, Notification.
- **Consumes:** Billing `InvoicePaid` → `ConfirmBillingIntentOnPayment` confirms a pay-first intent +
  correlates `sub-payment-confirmed` to resume a parked operation.

## 6. Processes (workflow) — with handler examples
- **Trigger:** `OperationFramework::trigger()` (process key from `subscription_operation_config`).
- **Reconciliation:** `SyncOperationFromProcess` on `ProcessInstanceEnded` closes the ledger.
- **Registered handlers (the toolbox):** Validate{Operation,Activation,PackageChange,HomePassChange},
  `EnterPendingStatusHandler`, Activate/Pause/Resume/Suspend/Terminate, Change{Package,HomePass},
  `PutActiveRestrictionsHandler`, `FulfillmentCallHandler`, `BillingIntentHandler`,
  `EquipmentPickupHandler`, `CreateShiftingWoHandler`.

**Worked handler — `EnterPendingStatusHandler` (topic `enter-pending-status`):**
> Invoked when the flow reaches the "flip to pending" node. **Reads:** `{subscriptionId,
> operationKind}`. **Does:** `SubscriptionService::setPendingStatus($sub,'PENDING_PAUSE',…)` — the
> transient flip that arms compensation. **Outputs:** none (the master is now `PENDING_PAUSE`).

**Worked handler — `BillingIntentHandler` (topic `billing-intent`):**
> **Reads:** `{subscriptionId, operationKind}` + the flow's fee config. **Does:** calls
> `BillingIntentService::emit()`; if the fee is **pay-first**, the intent stays `PENDING` and the flow
> **parks** `AWAITING_PAYMENT` until `InvoicePaid`. **Outputs:** `{intentConfirmed: bool}` (the next
> gateway proceeds only when true).

**Worked handler — `ActivateHandler` (topic `activate`):**
> **Reads:** `{subscriptionId}`. **Does:** `SubscriptionService::transitionStatus(ACTIVE)` (commits
> the rest state + emits `SubscriptionActivated`). **On fail:** `TaskResult::fail(retryable:true)`.

## 7. Policy & config
`subscription_operation_config` (enable/disable a kind, custom BPMN), the per-operation config tables,
cycle cadence (`cycle_period_days`/`cycle_frequency_months`) on the master.

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

## 10. Open items / deltas from `docs/design-text/`
- Some `subscription_upgrade_config` / `pause_config` fields are config-ahead-of-wiring (no gap today).
- Mid-cycle (upgrade/downgrade) **proration** runs through the BillingIntent path, distinct from the
  cycle-fee proration in `Billing/ChargeComputeService` — see `billing.md`.
- SUB-WF flow ordering now lives in `process_definition` data (the authored graph is the spec).
