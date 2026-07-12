# Workforce

Field-resource capability for contractors, technicians, skills, coverage, calendars, slots, capacity and work-order commitments.

## Use

Manage workforce resources through `routes/api.php`. Work-order assignment consumes eligible capacity; use `sophix:workforce:*` commands to inspect contractors and safely release stale commitments.

## Configure

Skills, coverage, calendars, slot capacities and assignment constraints are catalogs/configuration. Commitments are operational reservations with lifecycle history.

## Extend

Add scheduling strategies behind a stable interface and select them by operator policy. Preserve capacity atomically, make release idempotent and test concurrent booking and cancellation.

## Test

Workforce API and slot-commitment lifecycle scenarios are in `tests/Feature`.
