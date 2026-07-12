# Reporting

Operational reporting capability for read-optimized marts, freshness tracking, reconciliation and dashboard/export data.

## Use

Consumers query reports through `routes/api.php`; operations use `sophix:reporting:*` commands to inspect mart inventory, freshness and event-log reconciliation.

## Configure

Report definitions, refresh schedules, retention and freshness thresholds are configuration. Report marts are projections and must remain rebuildable from authoritative module data/events.

## Extend

Add a projection or report service with explicit source events, ownership and refresh policy. Avoid cross-module writes and business decisions based on stale reporting tables. Include reconciliation and authorization tests.

## Test

Dashboard, mart and reconciliation behavior is covered in `tests/Feature`.
