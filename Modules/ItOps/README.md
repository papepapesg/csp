# IT Operations

Operational control plane for service heartbeats, logs, worker visibility and audited pause/resume/restart requests.

## Use

Use `routes/api.php` and `sophix:itops:*` commands to review services, search logs and queue controlled service actions. Commands are designed for automation and support JSON output where applicable.

## Configure

Service inventory, heartbeat thresholds, retention and control permissions are configuration. Logs and control requests are operational history.

## Extend

Register new managed services and implement control executors behind explicit adapters. Never execute arbitrary shell input from catalog/config values; keep every mutation authorized and audited.

## Test

API, logging and command behavior is covered in `tests/Feature`.
