# Provisioning

Vendor-neutral service activation boundary for command dispatch, asynchronous polling, retry, desired/observed reconciliation and controlled force-sync.

## Use

Workflows submit provisioning commands through module services. Operations use `sophix:provisioning:*` commands and `routes/api.php` to inspect commands, run reconciliation and approve repairs.

## Configure

Targets, adapter bindings, profiles, retry policy and reconciliation thresholds are deployment configuration/catalogs. Commands, attempts and reconciliation items are operational history.

## Extend

Implement `ProvisioningAdapter`, register its class in `provisioning_adapter_config`, and map technology/service profiles as data. Adapters must support deterministic dispatch results, safe polling, observed-state reads, timeouts and contract tests. Core workflows should not contain vendor APIs.

## Exposed APIs

- `GET provisioning/commands`
- `GET provisioning/commands/{provisioningCommand}`
- `GET provisioning/force-sync-requests`
- `GET provisioning/reconciliation/items`
- `GET provisioning/reconciliation/runs`
- `POST provisioning/commands/{provisioningCommand}/retry`
- `POST provisioning/force-sync-requests/{forceSync}/approve`
- `POST provisioning/force-sync-requests/{forceSync}/cancel`
- `POST provisioning/force-sync-requests/{forceSync}/execute`
- `POST provisioning/reconcile`
- `POST provisioning/reconciliation/items/{item}/force-sync`
- `POST provisioning/reconciliation/run`

## Data models

- `ProvisioningAdapterConfig`
- `ProvisioningCommand`
- `ProvisioningCommandAttempt`
- `ProvisioningDesiredState`
- `ProvisioningForceSyncRequest`
- `ProvisioningObservedState`
- `ProvisioningReconciliationItem`
- `ProvisioningReconciliationRun`
- `ProvisioningTarget`

## Services

- `ProvisioningAdapterRegistry`
- `ProvisioningService`
- `ReconciliationService`

## Events

- `ProvisioningEvents`
- `ProvisioningEvents::COMMAND_CONFIRMED`
- `ProvisioningEvents::COMMAND_FAILED`
- `ProvisioningEvents::COMMAND_SENT`
- `ProvisioningEvents::FORCE_SYNC_APPROVED`
- `ProvisioningEvents::FORCE_SYNC_CANCELLED`
- `ProvisioningEvents::FORCE_SYNC_COMPLETED`
- `ProvisioningEvents::FORCE_SYNC_REQUESTED`
- `ProvisioningEvents::RECONCILE_ITEM_OPENED`
- `ProvisioningEvents::RECONCILE_MISMATCH`
- `ProvisioningEvents::RECONCILE_RUN_COMPLETED`
- `ProvisioningEvents::TOPIC`
- `SyncProvisioningOnAccountStatusChanged`

## Commands

- `sophix:provisioning:command-show`
- `sophix:provisioning:ops-fix`
- `sophix:provisioning:ops-status`
- `sophix:provisioning:poll-async`
- `sophix:provisioning:reconcile`

## Test

Adapter routing, command lifecycle and reconciliation are covered in `tests/Feature`.
