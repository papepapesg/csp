# Billing Intent

Coordinates payment-required business actions before final settlement, with billable-event catalog validation, confirmation, cancellation and timeout handling.

## Use

Consumers create intents through the module service/API and react to settlement events. Inspect stale or unsettled records with `php artisan sophix:billing-intent:ops-status --operator=<code>`.

## Configure

Billable events are module-owned catalog entries. `support_level` separates executable behavior from validation-only or reference values; `behavior_key` selects registered semantics.

## Extend

Register code for a new executable behavior before marking its catalog entry `EXECUTABLE`. Add state callbacks through stable contracts/events, never by coupling to a consumer controller.

## Exposed APIs

- `GET billing/billable-event-categories`
- `GET billing/billable-events`
- `GET billing/billable-events/{billableEvent}`
- `PATCH billing/billable-events/{billableEvent}`
- `POST billing/billable-events`
- `POST billing/billable-events/{billableEvent}/activate`
- `POST billing/billable-events/{billableEvent}/retire`

## Data models

- `BillableEvent`
- `BillableEventCategory`
- `BillingIntent`

## Services

- `BillableEventCatalogService`
- `BillingIntentService`

## Events

- `BillingEvents::BILLABLE_EVENT_CHANGED`
- `BillingEvents::TOPIC`

## Commands

- `sophix:billing-intent:ops-status`

## Test

Catalog and lifecycle tests are in `tests/Feature`.
