# Billing Adjustments

Governed credit/debit adjustment capability, including proposal, approval routing, limit checks, application and immutable decision history.

## Use

Create and decide adjustments through the billing adjustment API. Operations teams can inspect queues with `php artisan sophix:billing-adjustments:ops-status --operator=<code>`.

## Configure

Adjustment types are catalog data; thresholds, limits, GL mappings and approval chains are operator configuration. Executable approval behavior is implemented by the shared approval framework.

## Extend

Add adjustment scopes or application strategies as registered code, then reference them from catalog/config records. Do not branch on arbitrary catalog labels or rewrite applied financial history. Add migrations and feature tests for every new state transition.

## Test

Tests live in `tests/Feature`; `ModuleBootTest` protects command registration.
