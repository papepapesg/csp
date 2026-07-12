# Work Order

Generic field-work framework for creation, assignment, execution, SLA, skills, notes, attachments, phase handling and finalization.

## Use

Manage work orders through `routes/api.php` or services. Workforce provides capacity/commitments; OSR provides equipment/stock. Use `sophix:workorder:*` commands for queue review and controlled lifecycle actions.

## Configure

Work-order kinds, reason codes, SLA, skill requirements, assignment policy and flow parameters are catalogs/configuration. Work orders are operational state and histories are append-only.

## Extend

Build country or technology variations as configurable forms, checklists and flow handlers. Add code only for executable behavior, keep finalization idempotent, publish correlation context and avoid direct writes to calling modules.

## Test

Framework, assignment, SLA, support and shifting scenarios are in `tests/Feature`.
