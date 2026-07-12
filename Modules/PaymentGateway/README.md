# Payment Gateway

Boundary for external payment callbacks, verification, deduplication, status tracking and handoff to billing payment application.

## Use

Providers submit callbacks through `routes/api.php`; operations can inspect callbacks with `sophix:paymentgateway:*` commands. Accepted callbacks produce internal events rather than updating billing tables directly.

## Configure

Provider credentials, signature policy, timeout and callback mappings are deployment configuration/secrets. Callback records are an audit trail.

## Extend

Implement a provider adapter/verifier, register it by provider key, validate signatures before parsing business data, and add replay/idempotency contract tests using sanitized fixtures.

## Test

Callback lifecycle and operational API behavior are in `tests/Feature`.
