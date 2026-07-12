# Work Order

Generic field-work framework for creation, assignment, execution, SLA, skills, notes, attachments, phase handling and finalization.

## Use

Manage work orders through `routes/api.php` or services. Workforce provides capacity/commitments; OSR provides equipment/stock. Use `sophix:workorder:*` commands for queue review and controlled lifecycle actions.

## Configure

Work-order kinds, reason codes, SLA, skill requirements, assignment policy and flow parameters are catalogs/configuration. Work orders are operational state and histories are append-only.

## Extend

Build country or technology variations as configurable forms, checklists and flow handlers. Add code only for executable behavior, keep finalization idempotent, publish correlation context and avoid direct writes to calling modules.

## Exposed APIs

- `GET poc/work-orders`
- `GET work-orders`
- `GET work-orders/{workOrder}`
- `POST work-orders`
- `POST work-orders/{workOrder}/advance-phase`
- `POST work-orders/{workOrder}/assign`
- `POST work-orders/{workOrder}/attachments`
- `POST work-orders/{workOrder}/auto-assign`
- `POST work-orders/{workOrder}/cancel`
- `POST work-orders/{workOrder}/finalize`
- `POST work-orders/{workOrder}/finalize-first-confirm`
- `POST work-orders/{workOrder}/finalize-second-confirm`
- `POST work-orders/{workOrder}/notes`
- `POST work-orders/{workOrder}/reassign`
- `POST work-orders/{workOrder}/resolve`
- `POST work-orders/{workOrder}/shifting-flow`
- `POST work-orders/{workOrder}/start`
- `POST work-orders/{workOrder}/support-flow`

## Data models

- `WoAssignmentHistory`
- `WoAttachment`
- `WoFinalizationRequirement`
- `WoFlowConfig`
- `WoJobTypeCatalog`
- `WoNote`
- `WoNoteKind`
- `WorkOrder`
- `WorkOrderStatusHistory`

## Services

- `ShiftingFlowService`
- `SupportFlowService`
- `WorkOrderService`

## Events

- `WorkOrderEvents`
- `WorkOrderEvents::ASSIGNED`
- `WorkOrderEvents::ATTACHMENT_ADDED`
- `WorkOrderEvents::CANCELLED`
- `WorkOrderEvents::CREATED`
- `WorkOrderEvents::EQUIPMENT_BINDINGS_RECORDED`
- `WorkOrderEvents::ESCALATION_CANDIDATE`
- `WorkOrderEvents::FINALIZATION_PENDING`
- `WorkOrderEvents::FINALIZED`
- `WorkOrderEvents::NOTE_APPENDED`
- `WorkOrderEvents::PHASE_TRANSITIONED`
- `WorkOrderEvents::REASSIGNED`
- `WorkOrderEvents::RPT_LINKAGE_RECORDED`
- `WorkOrderEvents::SHIFTING_COMPLETED`
- `WorkOrderEvents::STARTED`
- `WorkOrderEvents::SUPPORT_COMPLETED`
- `WorkOrderEvents::TOPIC`

## Commands

- `sophix:workorder:fix`
- `sophix:workorder:ops-status`
- `sophix:workorder:show`

## Test

Framework, assignment, SLA, support and shifting scenarios are in `tests/Feature`.
