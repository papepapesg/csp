# Workflow

Native configurable process engine for definitions, instances, tokens, service/external tasks, user tasks, messages, timers and execution history.

## Use

Modules depend on the Foundation `WorkflowRuntime` contract. Operators use `sophix:workflow:*` commands to run workers, tick timers and inspect parked instances; the Studio manages definitions.

## Configure

Process nodes, edges, mappings, conditions and timeouts are versioned configuration. Instances/tasks are runtime state and event logs are history.

## Extend

Register focused task handlers in `TaskRegistry`; do not place module business logic in the engine. A replacement engine must implement `WorkflowRuntime` and preserve correlation, cancellation and idempotency semantics.

## Test

Engine execution, messages, timers and tasks are covered in `tests/Feature`.
