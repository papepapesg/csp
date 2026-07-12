# Billing Dunning

Debt-collection lifecycle for overdue accounts: assessment, escalation levels, restrictions, holds, recovery, clearing and archival.

## Use

The scheduled dunning runner advances eligible accounts. Review and controlled repair commands are listed under `php artisan list sophix:billing`; API endpoints support authorized operational actions.

## Configure

Dunning programs, levels, grace periods, actions and notification policies are operator-owned catalogs/configuration. Current account state and transition history are operational/ledger data.

## Extend

Model new escalation actions as registered handlers selected by program configuration. Keep subscription restrictions behind their service contract, emit events, and cover recovery and idempotency in tests.

## Exposed APIs

- `GET dunning`
- `GET dunning-programs`
- `GET dunning-programs/{code}`
- `GET dunning/pending-termination-review`
- `GET dunning/{account}`
- `GET dunning/{account}/history`
- `POST dunning-programs`
- `POST dunning-programs/{code}/new-version`
- `POST dunning/refresh-debt`
- `POST dunning/run`
- `POST dunning/{account}/admin-clear`
- `POST dunning/{account}/advance`
- `POST dunning/{account}/clear`
- `POST dunning/{account}/clear-without-payment`
- `POST dunning/{account}/confirm-termination`
- `POST dunning/{account}/extend-review`
- `POST dunning/{account}/force-terminate`
- `POST dunning/{account}/hold`

## Data models

- `DunningProgram`
- `DunningState`

## Services

- `DunningProgramResolver`
- `DunningService`

## Events

- `BillingEvents::DUNNING_ADMIN_OVERRIDE`
- `BillingEvents::DUNNING_CLEARED`
- `BillingEvents::DUNNING_DEBT_INCREASED`
- `BillingEvents::DUNNING_RECOVERY_FAILED`
- `BillingEvents::DUNNING_STAGE_ADVANCED`
- `BillingEvents::DUNNING_TERMINATION_PENDING`
- `BillingEvents::SUBSCRIPTION_ENTERED_DUNNING`
- `BillingEvents::SUBSCRIPTION_SUSPENDED_NP`
- `BillingEvents::TOPIC`
- `DunningEventBridge`

## Commands

- `sophix:billing:dunning-archive`
- `sophix:billing:dunning-fix`
- `sophix:billing:dunning-run`
- `sophix:billing:dunning-show`

## Test

Feature scenarios are in `tests/Feature/DunningTest.php`.
