# Notification ICN

Internal communications capability for staff-targeted notifications, acknowledgement windows, direct identity delivery, retry and expiry.

## Use

Create staff notifications through module services and process queues with `sophix:icn:retry` and `sophix:icn:expire`. Review outstanding acknowledgements using `sophix:icn:ops-status`.

## Configure

Audience resolution, channels, acknowledgement deadlines and retry policy are operator configuration. Notification and delivery records are operational history.

## Extend

Add audience resolvers or delivery adapters behind contracts; do not embed organization-specific recipients in code. Preserve acknowledgement idempotency and authorization boundaries.

## Test

Module boot coverage is in `tests/Feature`; add local delivery and acknowledgement scenarios for new behavior.
