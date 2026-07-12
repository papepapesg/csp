# Work Order Field Audit

Field-audit capability for campaigns, tasks, observations, discrepancies, approval and resolution across equipment and installations.

## Use

Audit APIs are composed in `../WorkOrder/routes/api.php`. Create campaigns/tasks, submit observations and resolve discrepancies. Review queues with `sophix:field-audit:ops-status`.

## Configure

Audit types, expected conditions, sampling, discrepancy severity and approval policy are catalogs/configuration. Observations and resolutions are audit history.

## Extend

Add configurable observation schemas and discrepancy rules before adding code. Integrate correction actions through owning module services/events, with approvals and traceable evidence.

## Exposed APIs

- `GET field-audit-discrepancies`
- `GET field-audit-tasks`
- `GET field-audit-tasks/{fieldAuditTask}`
- `GET field-audits`
- `GET field-audits/{fieldAudit}`
- `POST field-audit-campaigns`
- `POST field-audit-discrepancies/{fieldAuditDiscrepancy}/approval-outcome`
- `POST field-audit-discrepancies/{fieldAuditDiscrepancy}/resolve`
- `POST field-audit-tasks`
- `POST field-audit-tasks/{fieldAuditTask}/observations`
- `POST field-audits`
- `POST field-audits/{fieldAudit}/findings`

## Data models

- `FieldAudit`
- `FieldAuditCampaign`
- `FieldAuditDiscrepancy`
- `FieldAuditExpectedItem`
- `FieldAuditObservation`
- `FieldAuditTask`

## Services

- `FieldAuditCampaignService`
- `FieldAuditService`

## Events

- `CreateFieldAuditWorkOrder`

## Commands

- `sophix:field-audit:ops-status`

## Test

Module boot coverage is in `tests/Feature`; detailed legacy field-audit scenarios also remain under `../WorkOrder/tests/Feature` during transition.
