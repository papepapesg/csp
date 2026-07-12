# Billing Adjustments

Governed credit/debit adjustment capability, including proposal, approval routing, limit checks, application and immutable decision history.

## Use

Create and decide adjustments through the billing adjustment API. Operations teams can inspect queues with `php artisan sophix:billing-adjustments:ops-status --operator=<code>`.

## Configure

Adjustment types are catalog data; thresholds, limits, GL mappings and approval chains are operator configuration. Executable approval behavior is implemented by the shared approval framework.

## Extend

Add adjustment scopes or application strategies as registered code, then reference them from catalog/config records. Do not branch on arbitrary catalog labels or rewrite applied financial history. Add migrations and feature tests for every new state transition.

## Exposed APIs

- `GET adjustment-reason-codes`
- `GET adjustments`
- `GET adjustments/{adjustment}`
- `GET billing/bulk-reversals`
- `GET credit-notes/{note}`
- `POST adjustments`
- `POST adjustments/{adjustment}/approve`
- `POST adjustments/{adjustment}/cancel`
- `POST adjustments/{adjustment}/override-limit`
- `POST adjustments/{adjustment}/reject`
- `POST adjustments/{adjustment}/request-revision`
- `POST adjustments/{adjustment}/retry-application`
- `POST billing/bulk-reversals`
- `POST billing/bulk-reversals/preview`
- `POST billing/bulk-reversals/{batch}/approve`
- `POST billing/bulk-reversals/{batch}/reject`

## Data models

- `AdjustmentApprovalStep`
- `AdjustmentReasonCode`
- `AdjustmentRequest`
- `BulkReversalBatch`

## Services

- `AdjustmentService`
- `BulkReversalService`

## Events

- `BillingEvents::ADJUSTMENT_APPROVED`
- `BillingEvents::ADJUSTMENT_PROPOSED`
- `BillingEvents::ADJUSTMENT_REJECTED`
- `BillingEvents::BULK_REVERSAL_COMPLETED`
- `BillingEvents::INVOICE_CANCELLED`
- `BillingEvents::TOPIC`

## Commands

- `sophix:billing-adjustments:ops-status`

## Test

Tests live in `tests/Feature`; `ModuleBootTest` protects command registration.
