# Notification

Customer-notification capability for templates, rendering, channel dispatch, delivery attempts, retries and delivery history.

## Use

Publish notification requests through module services/events and manage templates through `routes/api.php`. Use `sophix:notification:*` commands to inspect and safely retry render or delivery failures.

## Configure

Templates, locales, channel policies, retry/backoff and operator channel bindings are catalogs/configuration. Delivery logs and attempts are operational history.

## Extend

Implement a channel adapter and register it by configuration key. Keep rendering separate from transport, make provider callbacks idempotent, redact sensitive payloads, and add contract plus retry tests.

## Test

Pipeline, template, delivery and ICN compatibility scenarios are in `tests/Feature`.
