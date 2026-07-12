# Workflow

Native configurable process engine for definitions, instances, tokens, service/external tasks, user tasks, messages, timers and execution history.

## Use

Modules depend on the Foundation `WorkflowRuntime` contract. Operators use `sophix:workflow:*` commands to run workers, tick timers and inspect parked instances; the Studio manages definitions.

## Configure

Process nodes, edges, mappings, conditions and timeouts are versioned configuration. Instances/tasks are runtime state and event logs are history.

## Extend

Register focused task handlers in `TaskRegistry`; do not place module business logic in the engine. A replacement engine must implement `WorkflowRuntime` and preserve correlation, cancellation and idempotency semantics.

## Exposed APIs

- `GET workflow/definitions`
- `GET workflow/definitions/{processDefinition}`
- `GET workflow/instances`
- `GET workflow/instances/{instance}`
- `GET workflow/palette`
- `GET workflow/tasks`
- `GET workflow/user-tasks`
- `POST workflow/definitions`
- `POST workflow/definitions/{processDefinition}/deploy`
- `POST workflow/definitions/{processDefinition}/validate`
- `POST workflow/incidents/{externalTask}/retry`
- `POST workflow/messages/correlate`
- `POST workflow/user-tasks/{userTask}/complete`
- `PUT workflow/definitions/{processDefinition}`

## Data models

- `ActivityLog`
- `ExternalTask`
- `MessageSubscription`
- `ProcessDefinition`
- `ProcessInstance`
- `UserTask`
- `WorkflowTimer`

## Services

- `GraphValidator`
- `ProcessInstanceEnded`
- `TaskRegistry`
- `WorkflowEngine`

## Events

- No module-specific event catalog or listener is currently registered.

## Commands

- `sophix:workflow:instance-show`
- `sophix:workflow:ops-status`
- `sophix:workflow:tick`
- `sophix:workflow:work`

## Test

Engine execution, messages, timers and tasks are covered in `tests/Feature`.
