# Workflow — As-Built Design (the process engine)

> **Module path:** `Modules/Workflow` · **Test:** `WorkflowEngineTest` · **See `00_SPINE.md` §4** — this
> doc adds the engine internals behind that pattern.

## 1. Purpose & boundaries
- **Owns:** the **native process engine** (FOUNDATION_CAMUNDA): process definitions (graphs), instances,
  external tasks, message subscriptions, timers, user tasks — and the **Studio** API to author/validate/
  deploy flows.
- **Does NOT own:** the business steps — those are `TaskHandler`s each module registers.
- **Job:** run config-defined, multi-step, retry-safe processes. *new flow = compose registered topics;
  new step = register one handler.*

## 📖 Scenarios (service + Foundation involvement)

### 1. Author → validate → deploy a flow (no code for the flow)
`POST /api/workflow/definitions` a node graph → `…/validate` (the graph validator checks every `topic`
is registered, inputs are wired, no dangling nodes) → `…/deploy`. *Proven by `WorkflowEngineTest`.*

### 2. Start an instance
`WorkflowEngine::start('sub-activate', businessKey, vars)` → a `process_instance` + an `external_task`
per service node (`CREATED`).

### 3. Worker drains a task
`sophix:workflow:work` fetch-and-locks `CREATED` tasks by topic, runs the handler,
`completeExternalTask` advances to the next node.

### 4. Exclusive gateway branches on a variable
A gateway routes on `{kycApproved: true}` vs default — the engine evaluates the condition against
instance variables. *Proven by `WorkflowEngineTest::test_exclusive_gateway_branches_on_variables`.*

### 5. Input mapping wires an upstream output into a downstream input
A node's declared output (`{provisioningRef}`) is mapped into a later node's input. *Proven by
`WorkflowEngineTest::test_input_mapping_wires_an_upstream_output_into_a_downstream_input`.*

### 6. Message catch parks → correlate resumes
A `messageCatch` node creates a `message_subscription`; the flow parks until
`correlateMessage('ful-install-finalized', businessKey, vars)` (e.g. from a `WorkOrderFinalized`
listener) resumes it.

### 7. Timer fires
A timer node creates a `workflow_timer`; `sophix:workflow:tick` fires due timers + reaps dead locks.

### 8. Strict outputs rejects an undeclared key
With `SOPHIX_WORKFLOW_STRICT_OUTPUTS`, a handler returning a key it didn't declare is rejected. *Proven
by `WorkflowEngineTest::test_strict_outputs_rejects_a_handler_that_returns_undeclared_keys`.*

### (bonus) 9. Reconcile on end
`ProcessInstanceEnded` lets a module close its ledger (e.g. Subscription `SyncOperationFromProcess`).

## 2. Data model — ≥4 sample rows + readings

### `process_instance` · `status`: `RUNNING|COMPLETED|CANCELLED|FAILED` & `external_task` · `status`: `CREATED|LOCKED|COMPLETED|FAILED`
```json
{ "instance_id":"pi_1","process_key":"sub-activate","business_key":"sub_1","status":"RUNNING" }
{ "instance_id":"pi_2","process_key":"ful-order-capture","business_key":"order_1","status":"COMPLETED" }
{ "et_1":{ "task_id":"et_1","instance_id":"pi_1","topic":"activate","status":"LOCKED","worker_id":"wf-w-1","locked_until":"…" } }
{ "et_2":{ "task_id":"et_2","instance_id":"pi_1","topic":"billing-intent","status":"CREATED" } }
```
**Reading:** an **instance** is one running flow keyed by `business_key` (the subscription/order id). An
**external_task** is a service node waiting to be worked; `CREATED` = runnable, `LOCKED` = a worker
claimed it (with `locked_until` — a dead worker's lock is reaped by `:tick`). The topic routes it to a
handler.

### `process_definition` (the flow graph, versioned + per-operator override)
```json
{ "id":"pd_1","process_key":"sub-activate","operator_code":"*","version":1,"status":"DEPLOYED" }
{ "id":"pd_2","process_key":"sub-activate","operator_code":"WIK","version":2,"status":"DEPLOYED" }
{ "id":"pd_3","process_key":"ful-order-capture","operator_code":"*","version":1,"status":"DEPLOYED" }
{ "id":"pd_4","process_key":"sub-pause","operator_code":"*","version":1,"status":"DRAFT" }
```
**Reading:** flows are **data**. pd_2 is a **WIK-specific override** of `sub-activate` (an operator can
fork a flow without code). `*` = the platform default. Only `DEPLOYED` definitions start instances; a
new market = a new definition row.

### `message_subscription` / `workflow_timer` / `user_task`
```json
{ "msg_1":{ "instance_id":"pi_3","message_name":"ful-install-finalized","business_key":"order_2","status":"WAITING" } }
{ "timer_1":{ "instance_id":"pi_4","fire_at":"2026-06-21T00:00:00Z","status":"PENDING" } }
{ "ut_1":{ "instance_id":"pi_5","topic":"manual-review","status":"OPEN","candidate_group":"billing-lead" } }
```
**Reading:** a `message_subscription` is a parked catch (resumed by `correlateMessage`); a
`workflow_timer` fires on `:tick`; a `user_task` parks for a human (claimed via the Studio inbox). These
are the three "wait" mechanisms.

## 3. Engine
| Component | Responsibility |
| --- | --- |
| `Engine/WorkflowEngine` | `start`, `complete/failExternalTask`, `correlateMessage`, timers |
| `Engine/TaskRegistry` | topic → `TaskHandler` registry (modules register steps) |
| graph validator | rejects unknown topics, unwired inputs, bad edges |
| `WorkflowWorkerCommand` / tick | the shared worker (drain) + timer/lock-reaper |

## 4. API surface (the Studio)
`/api/workflow/{palette,definitions,definitions/{id}/validate,definitions/{id}/deploy,instances}`.
`permission:workflow.*`.

## 5. Integration
- **Emits:** `ProcessInstanceEnded`. **Driven by:** every orchestrating module (`*WorkflowProvider`).

## 6. Processes
The engine itself; `sophix:workflow:work` + `:tick` are its heartbeat.

## 7. Policy & config
Flows are data (operator override by definition row); worker `--max/--sleep` tune throughput;
`SOPHIX_WORKFLOW_DRIVER` selects the engine.

## 8. Cross-module dependencies
- **Used by →** Subscription, Fulfillment, OSR, WorkOrder (their flows + handlers).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| strict outputs | a handler returns only declared keys (when enabled) | engine |
| retry-safe | a dead worker's locked tasks are reaped + re-queued | `:tick` |
| validated deploy | a flow can't deploy with an unknown topic / unwired input | graph validator |

## 10. Open items / deltas
- The native engine is the default; a Camunda driver is a seam for later.
