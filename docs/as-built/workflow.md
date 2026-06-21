# Workflow — As-Built Design (the process engine)

> **Module path:** `Modules/Workflow` · **Source-of-truth test:** `WorkflowEngineTest`
> **See also `00_SPINE.md` §4** — this doc adds the engine internals behind that pattern.

## 1. Purpose & boundaries
- **Owns:** the **native process engine** (FOUNDATION_CAMUNDA): process definitions (graphs), running
  instances, external tasks, message subscriptions, timers, user tasks — and the **Studio** API to
  author/validate/deploy flows.
- **Does NOT own:** the business steps — those are `TaskHandler`s registered by each module.
- **Job:** run config-defined, multi-step, retry-safe processes; "new flow = compose registered
  topics; new step = register a handler."

## 📖 Scenarios — read these first

### Scenario A — author and run a flow (no code for the flow)
1. A modeller `POST /api/workflow/definitions` a graph of nodes (service tasks with `topic`,
   gateways, message catches), `…/validate` it (the graph validator checks every `topic` is a
   registered handler, inputs are wired, no dangling nodes), then `…/deploy`.
2. A module calls `WorkflowEngine::start('my-flow', businessKey, vars)` → a `ProcessInstance` + an
   `ExternalTask` per service node (`CREATED`).
3. `sophix:workflow:work` fetch-and-locks `CREATED` tasks by topic, runs the handler, and
   `completeExternalTask` advances to the next node (exclusive gateways branch on variables; input
   mapping wires an upstream output into a downstream input).
- **Proven by:** `WorkflowEngineTest` (operator-override, gateway branching, input mapping, validator).

### Scenario B — a parked flow waits for an event
- A `messageCatch` node creates a `MessageSubscription`; the flow parks until
  `WorkflowEngine::correlateMessage(name, businessKey, vars)` is called (e.g. by a listener on
  `WorkOrderFinalized`), which resumes it. Timers (`WorkflowTimer`) fire via `sophix:workflow:tick`.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `process_definition` | the flow graph (data, versioned, per-operator override) | validated before deploy |
| `process_instance` | a running flow (status, variables, business_key) | one terminal end |
| `external_task` | a service-task to be worked (topic, status, lock) | fetch-and-lock, retry-safe |
| `message_subscription` / `workflow_timer` / `user_task` | catches, timers, human tasks | |
| `activity_log` | per-instance step audit | append-only |

## 3. Engine
| Component | Responsibility |
| --- | --- |
| `Engine/WorkflowEngine` | `start`, `completeExternalTask`/`failExternalTask`, `correlateMessage`, timers |
| `Engine/TaskRegistry` | topic → `TaskHandler` registry (modules register their steps) |
| graph validator | rejects unknown topics, unwired required inputs, bad edges |
| `WorkflowWorkerCommand` / tick | the shared worker (drain) + timer/lock-reaper |

## 4. API surface (the Studio)
`/api/workflow/{palette,definitions,definitions/{id}/validate,definitions/{id}/deploy,instances}`.
Guarded by `permission:workflow.*`.

## 5. Integration
- **Emits:** `ProcessInstanceEnded` (modules reconcile their ledgers, e.g. Subscription operations).
- **Driven by:** every orchestrating module (`*WorkflowProvider` registers handlers + listens).

## 6. Processes
The engine itself; the `sophix:workflow:work` + `:tick` workers are its heartbeat.

## 7. Policy & config
Flows are data (operator override by definition row); the worker `--max/--sleep` tune throughput.

## 8. Cross-module dependencies
- **Used by →** Subscription, Fulfillment, OSR, WorkOrder (their flows + handlers).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| strict outputs | a handler may only return declared output keys (when `SOPHIX_WORKFLOW_STRICT_OUTPUTS`) | engine |
| retry-safe | a dead worker's locked tasks are reaped + re-queued | `:tick` |
| validated deploy | a flow can't deploy with an unknown topic / unwired input | graph validator |

## 10. Open items / deltas
- The native engine is the default (`SOPHIX_WORKFLOW_DRIVER`); a Camunda driver is a seam for later.
