# IT Operations

Operational control plane for service heartbeats, logs, worker visibility and audited pause/resume/restart requests.

## Use

Use `routes/api.php` and `sophix:itops:*` commands to review services, search logs and queue controlled service actions. Commands are designed for automation and support JSON output where applicable.

## Configure

Service inventory, heartbeat thresholds, retention and control permissions are configuration. Logs and control requests are operational history.

## Extend

Register new managed services and implement control executors behind explicit adapters. Never execute arbitrary shell input from catalog/config values; keep every mutation authorized and audited.

## Exposed APIs

- `GET itops/logs`
- `GET itops/services`
- `GET noc/overview`
- `GET noc/sla-overdue`
- `GET noc/trace`
- `POST itops/services/{service}/restart`
- `POST noc/services/{service}/start`
- `POST noc/services/{service}/stop`

## Data models

- `ServiceControl`
- `ServiceHeartbeat`
- `SystemLog`

## Services

- No class under the module service/engine namespaces.

## Events

- No module-specific event catalog or listener is currently registered.

## Commands

- `sophix:itops:ops-status`
- `sophix:itops:service-control`
- `sophix:itops:service-show`

## Test

API, logging and command behavior is covered in `tests/Feature`.
