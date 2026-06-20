# WorkOrder — As-Built Design (Field Ops)

> **Capability codes:** WO-01 (lifecycle + dispatch), WO-01-FLOW-SUPPORT / -SHIFTING, FA-01/02/03
> (field audits) · **Module path:** `Modules/WorkOrder` · **Source-of-truth tests:**
> `Modules/WorkOrder/tests/Feature/*` (WorkOrderApi, AutoAssign, Framework, Support/ShiftingFlow,
> SlaSkills, FieldAudit*)

## 1. Purpose & boundaries
- **Owns:** the **work order** lifecycle (create → assign → start → finalize / cancel), its history,
  job-type catalog, finalization checklist, and the **field-audit** (FA) capability.
- **Does NOT own:** the workforce capacity it consumes (Workforce), the equipment (OSR), the order it
  may serve (Fulfillment). It executes field jobs.
- **Job:** dispatch and track field/desk work with SLA + skills, plus the FA inspection model.

## 2. Data model (selected)
| Table | Purpose | Invariants |
| --- | --- | --- |
| `work_order` | the WO: `type`, `status`, `priority`, `tech_region_id`, `source_type/ref`, SLA timestamps | state machine (PENDING→ASSIGNED→IN_PROGRESS→FINALIZATION_PENDING→COMPLETED \| CANCELLED) |
| `wo_status_history` / `wo_assignment_history` | append-only audit | |
| `wo_job_type_catalog` / `wo_flow_config` | operator job types + per-flow config | config |
| `wo_finalization_requirement` / `wo_note(+kind)` / `wo_attachment` | finalize checklist, notes, files | |
| `field_audit_campaign` / `…task` / `…expected_item` / `…observation` / `…discrepancy` | FA-01/02/03 model | expected-vs-observed → typed discrepancy → routed |

## 3. Services
| Service | Responsibility |
| --- | --- |
| `WorkOrderService` | lifecycle: `create`, `assign`, **`autoAssign`** (prefer OUTSOURCED contractor w/ region+skills+spare capacity via Workforce, else in-house staff), `start`, `finalize`, `cancel`; SLA captured at create |
| `SupportFlowService` / `ShiftingFlowService` | WO-01-FLOW orchestration (support resolution, shifting phases) |
| `FieldAuditCampaignService` | FA campaigns/tasks/observations; discrepancy severity+route via rules; risky routes (OSR correction/write-off) **EM-CFG-04** gated; WO-backed audit emits `FieldAuditWorkOrderRequested` |
| `FieldAuditService` | simpler field-audit submission path |

## 4. API surface
`/api/work-orders` (create gated by `permission:workorder.assign` + **`scope:TECH_REGION,tech_region_id`**),
assign/auto-assign/start/finalize/cancel/notes/attachments; `/api/work-orders/{id}/support-flow` &
`…/shifting-flow`; `/api/field-audit-campaigns`, `…tasks`, `…discrepancies`.

## 5. Integration (events) — topic `workorder.field`
- **Emits:** `WorkOrder{Created,Assigned,Started,FinalizationPending,Finalized,Reassigned,Cancelled}`,
  `WorkOrder{Support,Shifting}Completed`, `WorkOrderEscalationCandidate`, equipment-binding events,
  `FieldAuditWorkOrderRequested`.
- **Consumes:** `CreateFieldAuditWorkOrder` (FA `FieldAuditWorkOrderRequested` → build the WO-01 work
  order, link `wo_id`, move task ASSIGNED).
- **Downstream:** Fulfillment + Subscription resume on `WorkOrderFinalized`; Workforce releases capacity;
  Ticketing resolves on finalize; Reporting metric.

## 6. Processes
- **Flows:** support + shifting flows (process definitions) + handlers: `SiteVisitDecisionHandler`,
  `ResolutionGateHandler`, `FinalizeSupportHandler`, `MarkEscalationHandler`, `CheckWarrantyHandler`,
  `MarkPhaseHandler`, `FinalizeShiftingHandler`, `CaptureBindingsHandler`.

## 7. Policy & config
Job-type catalog, flow config, finalization requirements, SLA-by-priority (operator-tunable);
discrepancy routing via `rules.field_audit.<type>.discrepancy`.

## 8. Cross-module dependencies
- **Calls →** Workforce (`ContractorAvailabilityService` for auto-assign capacity), Foundation Approvals
  (FA risky routes).
- **Called by →** Fulfillment (install WO), OSR (swap WO), Subscription (shifting WO), Workforce
  (capacity consume/release on WO lifecycle).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| WO-01 §3 | only the allowed status transitions are permitted | `WorkOrderService::transition` |
| EM-02 §5.1/5.2 | auto-assign atomically commits contractor capacity | `autoAssign` + Workforce |
| FA risky route | OSR correction / write-off gated by EM-CFG-04 | `FieldAuditCampaignService::route` |
| EM-CFG-03 §8.5 | WO create is TECH_REGION scope-gated | `scope` middleware |

## 10. Open items / deltas
- `FieldAuditWorkOrderRequested` now has a consumer (WO created) — earlier orphan, fixed.
- WO geolocation (lat/long) + per-technician performance metric are additive (optional backlog).
