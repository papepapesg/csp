# Subscription — As-Built Design

> **Capability codes:** SUB-LM-01 (master), SUB-WF-FRAMEWORK-01, SUB-WF-* (operations),
> SUB-WF-RESTRICT-01 · **Module path:** `Modules/Subscription`
> **Source-of-truth tests:** `Modules/Subscription/tests/Feature/*` (e.g. `SubscriptionApiTest`,
> restriction + operation-framework tests)

## 1. Purpose & boundaries
- **Owns:** the **subscription master row** (`subscription`) — the single writer of `status_code`
  and the cycle anchor — and the **operation ledger** (`subscription_operation`) that tracks every
  lifecycle command.
- **Does NOT own:** the money math (Billing), the network (Provisioning), the install/field work
  (WorkOrder), the package catalog (Catalog). It **orchestrates** them via workflow + events.
- **Job:** turn a lifecycle command (activate / pause / resume / suspend-NP / upgrade / downgrade /
  relocate / migrate / terminate / restrict) into a governed, idempotent, single-in-flight
  operation whose steps are config-defined.

## 📖 Scenarios — read these first

### Scenario A — agent pauses an active subscription
1. **Request:** `POST /api/subscriptions/sub_123/pause`
   ```json
   { "reason_code": "CUSTOMER_TRAVEL", "resume_at": "2026-08-01" }
   ```
2. **Service:** `OperationController@pause` → `OperationFramework::trigger($sub, 'PAUSE', input)`:
   resolves the process key (`subscription_operation_config` for `WIK`/`PAUSE`, else `sub-pause`),
   passes the **single-in-flight** check, writes a `subscription_operation` (`INITIATED`), starts the
   `sub-pause` workflow.
3. **Workflow:** `EnterPendingStatusHandler` flips the master to **`PENDING_PAUSE`** (transient) →
   `PauseHandler` commits **`PAUSED`** via `SubscriptionService::transitionStatus` and opens a
   `subscription_pause_history` row.
4. **Events:** emits `SubscriptionPaused` → Billing pauses dunning, Notification notifies the customer.
5. **State:** `subscription.status_code`: `ACTIVE → PENDING_PAUSE → PAUSED`; operation ledger closes
   `COMPLETED` via `ProcessInstanceEnded → SyncOperationFromProcess`.

### Scenario B — a second command arrives while one is in flight
- Agent triggers `upgrade` while the pause above is still running (`final_state IS NULL`).
- `OperationFramework::trigger` finds an in-flight **non-RESTRICT** op → throws
  `DomainException::conflict('Another operation is already in progress', nextAction: WAIT_FOR_OPERATION)`
  → HTTP **409**. (A `RESTRICT` op would be allowed — it's non-exclusive, R-SUB-WF-FW-2.)

### Scenario C — idempotent retry
- The agent's client retries `pause` with the **same `Idempotency-Key`** after a timeout.
- `trigger` finds the existing `subscription_operation` by (operator, key) and **returns the original**
  — no second workflow, no double-pause.

> Proven by `Modules/Subscription/tests/Feature/*` (operation-framework + restriction + API tests).

## 2. Data model
| Table | Purpose | Key invariants |
| --- | --- | --- |
| `subscription` | the master: `status_code`, cycle anchor (`current_cycle_start/end`, `cycle_period_days`/`cycle_frequency_months`), package refs, billing mode | one writer (`SubscriptionService`); `cyclePeriod()` = days else months |
| `subscription_operation` | per-command ledger: kind, `bpmn_process_key/instance_id`, idempotency key+hash, `prior_subscription_status`, `current_state`, `final_state` | **idempotent** by (operator, key); **single in-flight** non-RESTRICT op per subscription (partial unique index; `final_state IS NULL`) |
| `subscription_restriction` | active partial-service restrictions | RESTRICT ops are non-exclusive |
| `subscription_operation_config` | per-(operator,kind) process key + enabled flag | a kind can be disabled, or pointed at a custom BPMN, **as config** |
| `subscription_pause_config` / `…suspend_np_config` / `…upgrade_config` / `…restrict_config` | per-operator policy for each operation | no-code tuning |
| `subscription_pause_history` | append-only pause periods | |

**Statuses** (`Subscription::*`): `CREATED`, `PENDING_ACTIVATION`, `ACTIVE`, `PENDING_PAUSE`,
`PENDING_RESUME`, `PENDING_SUSPEND_NP`, `SUSPENDED`, `PAUSED`, `RESTRICTED`, `PENDING_UPGRADE`,
`PENDING_DOWNGRADE`, `PENDING_RELOCATION`, `PENDING_MIGRATION`, `PENDING_TERMINATION`, `TERMINATED`,
`RETIRED`. The transient `PENDING_*` states are the compensation hook (see §9).

## 3. Services & responsibilities
| Service | Responsibility | Key methods |
| --- | --- | --- |
| `SubscriptionService` | the **only writer** of the master row | `create()`, `setPendingStatus()` (transient flip), `transitionStatus($sub, $status, $extra, $eventType?)` (commit to a rest state + lifecycle timestamp + emit the status-specific event), pause open/close |
| `OperationFramework` | start + track every operation | `trigger()` (idempotency + single-in-flight + resolve process key + start workflow + write ledger), `cancel()` (cancel instance + compensate `PENDING_*` revert) |
| `RestrictionService` | partial-service restrictions (ADD/REMOVE) | gated, non-exclusive; the one op that does not change `status_code` |

## 4. API surface (`Modules/Subscription/routes/api.php`)
| Method + path | Permission | Idempotent | Scope |
| --- | --- | --- | --- |
| `POST /api/subscriptions` | `subscription.create` | yes | — |
| `GET /api/subscriptions[/{id}]` | `subscription.read` | — | — |
| `POST /api/subscriptions/{id}/activate` | `subscription.activate` | — | — |
| `POST /api/subscriptions/{id}/{pause\|resume\|terminate\|upgrade\|downgrade\|relocate\|migrate\|suspend-np}` | `subscription.manage` | — | — |
| `POST/DELETE /api/subscriptions/{id}/restrictions[/{code}]` | `subscription.manage` | yes | — |
| `GET …/operations`, `…/in-flight-operation`, `…/operations/{op}` | `subscription.read` | — | — |
| `POST /api/subscription-operations/{op}/cancel` | `subscription.manage` | — | — |

Controllers (`OperationController`, `RestrictionController`, `SubscriptionController`) validate and
call the services; each command ultimately calls `OperationFramework::trigger(kind, input)`.

## 5. Integration (events) — topic `subscription.lifecycle`
- **Emits:** `SubscriptionCreated`, `SubscriptionActivated`, `SubscriptionSuspended`,
  `SubscriptionSuspendedForNonPayment`, `SubscriptionPaused`, `SubscriptionResumed`,
  `SubscriptionTerminated`, `SubscriptionStatusChanged`, `Subscription{Upgraded,Downgraded,Relocated,
  Migrated}` (+ matching `*Rejected`), `SubscriptionRestriction{Added,Removed}`, and operation-ledger
  events `SubscriptionOperation{Started,Completed,Failed,Cancelled}`.
- **Consumers (examples):** Billing (`SubscriptionActivated`/`…Terminated` drive cycle anchoring &
  the `subscriptions_*` reporting metrics); Reporting projector; Notification (customer notices).
- **Consumes:** Billing's `InvoicePaid` → `ConfirmBillingIntentOnPayment` confirms a pay-first
  billing intent and correlates `sub-payment-confirmed` to resume a parked operation.

## 6. Processes (workflow) — the heart of the module
- **Trigger:** `OperationFramework::trigger()` resolves the process key from
  `subscription_operation_config` (else convention `sub-<kind-slug>`), starts the engine, and writes
  the ledger. Variables carry **IDs + flags only** (subscriptionId, operationId, kind, customerId).
- **Flows are data** (`process_definition`): a new market/operator = a new definition row, not code.
- **Reconciliation:** `SyncOperationFromProcess` listens for `ProcessInstanceEnded` and closes the
  operation ledger (sets `final_state`, emits Completed/Failed).
- **Registered handlers (topics)** — the toolbox these flows compose (`SubscriptionWorkflowProvider`):
  `ValidateOperationHandler`, `ValidateActivationHandler`, `ValidatePackageChangeHandler`,
  `ValidateHomePassChangeHandler`, `EnterPendingStatusHandler` (flip to a `PENDING_*` state),
  `ActivateHandler`, `PauseHandler`, `ResumeHandler`, `SuspendHandler`, `TerminateHandler`,
  `ChangePackageHandler`, `ChangeHomePassHandler`, `PutActiveRestrictionsHandler`,
  `FulfillmentCallHandler` (→ Provisioning broadcast), `BillingIntentHandler` (→ Billing pay-first
  gate, parks AWAITING_PAYMENT), `EquipmentPickupHandler`, `CreateShiftingWoHandler` (→ WorkOrder).

## 7. Policy & config (no-code knobs)
- `subscription_operation_config` — enable/disable a kind per operator, or point it at a custom BPMN.
- `subscription_{pause,suspend_np,upgrade,restrict}_config` — per-operator operation policy.
- Cycle cadence — `cycle_period_days` / `cycle_frequency_months` on the master (drives proration &
  cycle close in Billing).

## 8. Cross-module dependencies
- **Calls →** Provisioning (`FulfillmentCallHandler` → `ProvisioningService::broadcast`), Billing
  (`BillingIntentHandler` → `BillingIntentService::emit`), WorkOrder (`CreateShiftingWoHandler`).
- **Called by →** Fulfillment (`TriggerActivationHandler` → `OperationFramework::trigger('ACTIVATE')`),
  Billing dunning (suspend-NP / restriction intents), ILM account-status sync.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-SUB-WF-FW-1 | ≤ 1 in-flight non-RESTRICT operation per subscription | `OperationFramework::trigger` + partial unique index |
| R-SUB-WF-FW-2 | RESTRICT operations are non-exclusive | `trigger(exclusive: false)` |
| R-SUB-WF-FW-3 | cancelling an op reverts a transient `PENDING_*` master to `prior_subscription_status` | `OperationFramework::cancel` |
| R-SUB-WF-FW-7 | process key is config-resolved; a disabled kind is rejected | `resolveProcessKey` |
| (idempotency) | same (operator, key) returns the original operation | `trigger` |
| (single writer) | only `SubscriptionService` mutates `status_code` | by construction |

## 10. Open items / deltas from `docs/design-text/`
- Some per-operation config tables (`subscription_upgrade_config`, parts of `pause_config`) carry
  fields not yet read by a handler — config surface ahead of policy wiring (no behaviour gap today).
- Mid-cycle (upgrade/downgrade) **proration** runs through the BillingIntent path, distinct from the
  cycle-fee proration in `Billing/ChargeComputeService` — document both when writing `billing.md`.
- The original SUB-WF DDs describe flows that are now **data** (`process_definition`); the authored
  flow graphs, not code, are the spec for step ordering.
