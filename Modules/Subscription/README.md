# Subscription

Owns the customer service lifecycle: activation, status transitions, restrictions, pause/resume, suspension, termination, upgrade, relocation and other governed operations.

## Use

Use `routes/api.php` to create/query subscriptions and initiate operations. `OperationFramework` serializes state-changing work, enforces timeouts and coordinates workflow, billing, work orders and provisioning. Operations use `sophix:subscription:*` commands.

## Configure

Status catalogs, transition maps, per-operation policy, timeouts and restrictions are configuration. Subscriptions and operations are state; transition histories are ledgers.

## Extend

Add an operation as a focused workflow/handler set using stable module contracts. Configure eligibility and transitions as data, keep external effects idempotent, provide compensation, and test concurrent/in-flight behavior.

## Test

Lifecycle, restriction and operation-specific scenarios are in `tests/Feature`.
