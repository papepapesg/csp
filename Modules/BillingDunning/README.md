# Billing Dunning

Debt-collection lifecycle for overdue accounts: assessment, escalation levels, restrictions, holds, recovery, clearing and archival.

## Use

The scheduled dunning runner advances eligible accounts. Review and controlled repair commands are listed under `php artisan list sophix:billing`; API endpoints support authorized operational actions.

## Configure

Dunning programs, levels, grace periods, actions and notification policies are operator-owned catalogs/configuration. Current account state and transition history are operational/ledger data.

## Extend

Model new escalation actions as registered handlers selected by program configuration. Keep subscription restrictions behind their service contract, emit events, and cover recovery and idempotency in tests.

## Test

Feature scenarios are in `tests/Feature/DunningTest.php`.
