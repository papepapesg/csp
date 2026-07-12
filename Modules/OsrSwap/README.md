# OSR Swap

Equipment swap and RMA capability covering request approval, replacement issue, return tracking and vendor handoff.

## Use

Start swap requests through module services/APIs and inspect active workflow queues with `sophix:osr-swap:ops-status`. Equipment and stock mutations are delegated to core OSR.

## Configure

Swap reasons, warranty/RMA policy, approval requirements and vendor routing are configuration/catalog data. Swap transitions and handoffs are operational history.

## Extend

Implement vendor RMA adapters behind a stable contract and select them through deployment configuration. Preserve compensation and idempotency when stock or workflow steps fail.

## Test

Module boot coverage is in `tests/Feature`; add swap, return and adapter contract scenarios here.
