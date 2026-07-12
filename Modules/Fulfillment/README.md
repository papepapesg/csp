# Fulfillment

Orchestrates order capture from commercial acceptance through deposit, subscription creation, installation, KYC, activation and completion or compensation.

## Use

Create and manage fulfillment orders through `routes/api.php`. The `ful-order-capture` workflow performs the journey; workers advance external tasks and published work-order/KYC events resume message waits. Use `sophix:fulfillment:ops-status` for parked orders.

## Configure

Journey sequencing is a workflow definition; deposit, KYC and activation policies are configuration/rules. Orders and steps are operational state/history.

## Extend

Prefer editing the workflow and adding focused task handlers. Keep handlers idempotent, compensate created artifacts on cancellation, and communicate across modules through contracts or events.

## Test

The end-to-end configurable journey is covered in `tests/Feature/FulfillmentJourneyTest.php`.
