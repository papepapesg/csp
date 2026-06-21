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
`WorkflowEngine::start('sub-activate', businessKey, vars)` → a `process_instance` + a `workflow_external_task`
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

### `process_instance` · `status`: `RUNNING|COMPLETED|FAILED|CANCELLED|SUSPENDED` & `workflow_external_task` · `status`: `CREATED|LOCKED|COMPLETED|FAILED|INCIDENT`
```json
{ "instance_id":"pi_1","definition_id":"pdef_1","process_key":"sub-activate","definition_version":1,"operator_code":"WIK","business_key":"sub_1","variables":{"subscriptionId":"sub_1"},"status":"RUNNING","active_nodes":["activate"],"correlation_id":"sub_1","error_message":null,"started_at":"2026-06-20T10:00:00Z","ended_at":null }
{ "instance_id":"pi_2","definition_id":"pdef_3","process_key":"ful-order-capture","definition_version":1,"operator_code":"WIK","business_key":"order_1","variables":{"orderId":"order_1"},"status":"COMPLETED","active_nodes":[],"correlation_id":"order_1","error_message":null,"started_at":"2026-06-20T09:00:00Z","ended_at":"2026-06-20T09:05:00Z" }
{ "instance_id":"pi_6","definition_id":"pdef_1","process_key":"sub-activate","definition_version":1,"operator_code":"WIK","business_key":"sub_9","variables":{"subscriptionId":"sub_9"},"status":"FAILED","active_nodes":["activate"],"correlation_id":"sub_9","error_message":"NMS rejected provisioning","started_at":"2026-06-20T10:10:00Z","ended_at":null }
{ "et_1":{ "task_id":"et_1","instance_id":"pi_1","node_id":"activate","topic":"activate","operator_code":"WIK","business_key":"sub_1","variables":{"subscriptionId":"sub_1"},"status":"LOCKED","worker_id":"wf-w-1","locked_until":"2026-06-20T10:01:00Z","retries":3,"error_message":null,"completed_at":null } }
{ "et_2":{ "task_id":"et_2","instance_id":"pi_1","node_id":"billing-intent","topic":"billing-intent","operator_code":"WIK","business_key":"sub_1","variables":null,"status":"CREATED","worker_id":null,"locked_until":null,"retries":3,"error_message":null,"completed_at":null } }
```
**Reading:** an **instance** is one running flow keyed by `business_key` (the subscription/order id),
spawned from a `definition_id`+`definition_version`; `variables` carries ids/control flags and
`active_nodes` the nodes currently waiting. An **external task** is a service node waiting to be worked;
`CREATED` = runnable, `LOCKED` = a worker (`worker_id`) claimed it until `locked_until` — a dead worker's
lock is reaped by `:tick`, decrementing `retries` (0 → `INCIDENT`). The `topic` routes it to a handler.

### `process_definition` (the flow graph, versioned + per-operator override)
```json
{ "definition_id":"pdef_1","process_key":"sub-activate","version":1,"operator_code":null,"name":"Subscription Activation","description":"Default activation flow","graph":{"nodes":[],"edges":[]},"status":"DEPLOYED","created_by":"u_studio","deployed_at":"2026-06-01T00:00:00Z" }
{ "definition_id":"pdef_2","process_key":"sub-activate","version":2,"operator_code":"WIK","name":"Subscription Activation (WIK)","description":"WIK override","graph":{"nodes":[],"edges":[]},"status":"DEPLOYED","created_by":"u_studio","deployed_at":"2026-06-10T00:00:00Z" }
{ "definition_id":"pdef_3","process_key":"ful-order-capture","version":1,"operator_code":null,"name":"Order Capture","description":null,"graph":{"nodes":[],"edges":[]},"status":"DEPLOYED","created_by":"u_studio","deployed_at":"2026-06-02T00:00:00Z" }
{ "definition_id":"pdef_4","process_key":"sub-pause","version":1,"operator_code":null,"name":"Subscription Pause","description":null,"graph":{"nodes":[],"edges":[]},"status":"DRAFT","created_by":"u_studio","deployed_at":null }
```
**Reading:** flows are **data**. pdef_2 is a **WIK-specific override** of `sub-activate` (an operator can
fork a flow without code). `operator_code=null` = the platform default. Only `DEPLOYED` definitions start
instances (a `DRAFT` like pdef_4 is still being authored); a new market = a new definition row. The
`graph` is the React-Flow node/edge JSON authored in the Studio.

### `workflow_message_subscription` / `workflow_timer` · `status`: `PENDING|FIRED|CANCELLED` / `workflow_user_task` · `status`: `OPEN|CLAIMED|COMPLETED|CANCELLED`
```json
{ "msg":{ "id":11,"instance_id":"pi_3","node_id":"await-install","message_name":"ful-install-finalized","correlation_key":"order_2" } }
{ "msg":{ "id":12,"instance_id":"pi_7","node_id":"await-kyc","message_name":"kyc-decision","correlation_key":"sub_4" } }
{ "timer":{ "id":21,"instance_id":"pi_4","node_id":"grace-window","fire_at":"2026-06-21T00:00:00Z","status":"PENDING" } }
{ "ut":{ "task_id":"ut_1","instance_id":"pi_5","node_id":"manual-review","name":"Manual review","candidate_group":"billing-lead","assignee":null,"variables":{"reason":"high-value"},"status":"OPEN","due_at":"2026-06-22T00:00:00Z","completed_at":null } }
```
**Reading:** a `message_subscription` is a parked catch keyed by `message_name`+`correlation_key`
(resumed by `correlateMessage`); a `workflow_timer` fires its node on `:tick` when `fire_at` passes; a
`user_task` parks for a human — `OPEN` until a member of `candidate_group` claims it (sets `assignee`,
`CLAIMED`) via the Studio inbox. These are the three "wait" mechanisms.

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
