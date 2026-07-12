# Provisioning

Vendor-neutral service activation boundary for command dispatch, asynchronous polling, retry, desired/observed reconciliation and controlled force-sync.

## Use

Workflows submit provisioning commands through module services. Operations use `sophix:provisioning:*` commands and `routes/api.php` to inspect commands, run reconciliation and approve repairs.

## Configure

Targets, adapter bindings, profiles, retry policy and reconciliation thresholds are deployment configuration/catalogs. Commands, attempts and reconciliation items are operational history.

## Extend

Implement `ProvisioningAdapter`, register its class in `provisioning_adapter_config`, and map technology/service profiles as data. Adapters must support deterministic dispatch results, safe polling, observed-state reads, timeouts and contract tests. Core workflows should not contain vendor APIs.

## Test

Adapter routing, command lifecycle and reconciliation are covered in `tests/Feature`.
