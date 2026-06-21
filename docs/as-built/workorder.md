# WorkOrder — As-Built Design (Field Ops)

> **Capability codes:** WO-01 (lifecycle + dispatch), WO-01-FLOW-SUPPORT/-SHIFTING, FA-01/02/03 (field
> audits) · **Module path:** `Modules/WorkOrder` · **Tests:** `WorkOrderApi`, `AutoAssign`, `Framework`,
> `Support/ShiftingFlow`, `SlaSkills`, `FieldAudit*`

## 1. Purpose & boundaries
- **Owns:** the **work order** lifecycle (create → assign → start → finalize/cancel), its history,
  job-type catalog, finalization checklist, and the **field-audit** capability.
- **Does NOT own:** the workforce capacity it consumes (Workforce), the equipment (OSR), the order it
  serves (Fulfillment). It executes field/desk work.
- **Job:** dispatch + track field work with SLA & skills, and run FA inspections.

## 📖 Scenarios (service + Foundation involvement)

### 1. Create + auto-assign an install WO (scope-gated)
`POST /api/work-orders {type:INSTALLATION, tech_region_id:'KE-NRB-KAREN'}` passes
`permission:workorder.assign` **and** `scope:TECH_REGION,tech_region_id` (`Rbac/EnforceScope` — a
Karen-scoped dispatcher can't create a Mombasa WO). WO `PENDING`, SLA due-time stamped from priority.
`autoAssign` asks Workforce for a contractor with region+skill+**spare capacity** and atomically commits
a slot → `ASSIGNED`. *Proven by `WorkOrderApiTest`, `WorkOrderAutoAssignTest`.*

### 2. Auto-assign falls back to in-house staff
If no OUTSOURCED contractor has capacity, `autoAssign` matches an in-house `StaffMember` on skills.
*Shows: the dispatch strategy + Workforce dependency.* *Proven by `WorkOrderAutoAssignTest`.*

### 3. Support flow — resolve or escalate
`startSupportFlow` runs `osr`/support process steps: `SiteVisitDecisionHandler` →
`ResolutionGateHandler` (resolved? → `FinalizeSupportHandler`; else `MarkEscalationHandler` spawns a QCS
WO). *Foundation: workflow toolbox.* *Proven by `WorkOrderSupportFlowTest`.*

### 4. Shifting flow — phased relocation
`startShiftingFlow` walks phases via `MarkPhaseHandler`/`CaptureBindingsHandler`/`FinalizeShiftingHandler`
(`PHASE_TRANSITIONED`, `SHIFTING_COMPLETED`). *Proven by `WorkOrderShiftingFlowTest`.*

### 5. Field audit — clean observation closes the task
`POST …/field-audit-tasks/{id}/observations` with the expected serial → `FieldAuditCampaignService`
finds no discrepancy → task `CLOSED`, `FieldAuditTaskClosed`. *Proven by `FieldAuditCampaignTest`.*

### 6. Field audit — missing unit → HIGH → RMA recovery
`presenceStatus:MISSING` → discrepancy `MISSING`; `rules.field_audit.equipment.discrepancy` rates it
`HIGH` + routes `CREATE_RMA_RECOVERY` → an OSR RMA request is emitted. *Foundation: rules.* *Proven by
`FieldAuditCampaignTest`.*

### 7. Field audit — wrong serial → EM-CFG-04 → OSR correction
Wrong serial → `WRONG_SERIAL` routed `REQUEST_OSR_CORRECTION` (**risky**) → EM-CFG-04 request →
discrepancy `PENDING_APPROVAL`; approval → the OSR correction is emitted. *Proven by
`FieldAuditCampaignTest::test_wrong_serial_requires_em_cfg_04_approval_before_osr_correction`.*

### 8. A WO-backed audit creates its work order
A task created with `createWorkOrder=true` emits `FieldAuditWorkOrderRequested` → `CreateFieldAuditWorkOrder`
(listener) builds a `FIELD_AUDIT` WO, links `wo_id`, moves the task `ASSIGNED`. *Foundation: outbox
listener.* *Proven by `FieldAuditCampaignTest`.*

### (bonus) 9. Finalization is gated on a checklist
`finalize` is rejected unless the `wo_finalization_requirement` items are satisfied (R-WO finalize gate).

## 2. Data model — ≥4 sample rows + readings

### `work_order` · `type`: `INSTALLATION|SUPPORT|SHIFTING|RELOCATION|EQUIPMENT|NOC|FIELD_AUDIT` · `status`: `PENDING|ASSIGNED|IN_PROGRESS|FINALIZATION_PENDING|COMPLETED|CANCELLED` · `priority`: `LOW|NORMAL|HIGH|URGENT` · `source_type`: `TICKET|SUBSCRIPTION_OP|FULFILLMENT|MANUAL|FIELD_AUDIT`
```json
{ "work_order_id":"wo_1","type":"INSTALLATION","status":"COMPLETED","priority":"NORMAL","tech_region_id":"KE-NRB-KAREN","source_type":"FULFILLMENT","source_ref":"order_1" }
{ "work_order_id":"wo_2","type":"SUPPORT","status":"IN_PROGRESS","priority":"URGENT","source_type":"TICKET","source_ref":"tkt_9" }
{ "work_order_id":"wo_3","type":"SHIFTING","status":"ASSIGNED","priority":"NORMAL","source_type":"SUBSCRIPTION_OP" }
{ "work_order_id":"wo_4","type":"FIELD_AUDIT","status":"PENDING","priority":"LOW","source_type":"FIELD_AUDIT","source_ref":"fat_1" }
```
**Reading:** `type` picks the flow + skills; `source_type/ref` is the **origin** (a fulfillment order, a
ticket, a subscription op, a field-audit task) — the back-link other modules resume on. `priority` sets
the SLA window at create (URGENT=4h … LOW=168h). The status is the lifecycle state machine.

### `wo_job_type_catalog` & `wo_finalization_requirement`
```json
{ "job_type_code":"FTTH_INSTALL","wo_type":"INSTALLATION","required_skills":["fiber-install"],"sla_hours":24 }
{ "job_type_code":"HFC_INSTALL","wo_type":"INSTALLATION","required_skills":["coax-install"],"sla_hours":24 }
{ "job_type_code":"NO_SIGNAL","wo_type":"SUPPORT","required_skills":["diagnostics"],"sla_hours":4 }
{ "requirement":{ "wo_type":"INSTALLATION","code":"ONT_SERIAL","mandatory":true } }
```
**Reading:** the **job-type catalog** is operator config — it drives skill-matching for auto-assign and
the per-type SLA. Finalization requirements are the close-out checklist (e.g. must capture the ONT
serial) enforced at `finalize`.

### `field_audit_task` · `audit_type`: `EQUIPMENT|NETWORK|KYC` · `status`: `CREATED|ASSIGNED|DISCREPANCY_OPEN|CLOSED`
```json
{ "audit_task_id":"fat_1","audit_type":"EQUIPMENT","task_type":"CUSTOMER_PREMISES","customer_id":"CUS-1","status":"CREATED" }
{ "audit_task_id":"fat_2","audit_type":"EQUIPMENT","status":"DISCREPANCY_OPEN","wo_id":"wo_4" }
{ "audit_task_id":"fat_3","audit_type":"NETWORK","task_type":"PLANT","status":"CLOSED" }
{ "audit_task_id":"fat_4","audit_type":"KYC","status":"ASSIGNED" }
```
**Reading:** one capability, **audit_type-driven** (equipment count, network plant, KYC re-check). A
task moves `CREATED → ASSIGNED → (DISCREPANCY_OPEN | CLOSED)`. `wo_id` set = WO-backed (a tech is
dispatched); otherwise it's a desk/mobile task.

### `field_audit_discrepancy` · `discrepancy_type`: `MISSING|WRONG_SERIAL|DAMAGED|FOUND_EXTRA|WRONG_LOCATION|NOT_ACCESSIBLE` · `severity`: `LOW|MEDIUM|HIGH` · `route_action`: `CREATE_TICKET|CREATE_RMA_RECOVERY|REQUEST_OSR_CORRECTION|REQUEST_WRITE_OFF|NO_ACTION` · `status`: `OPEN|ROUTED|PENDING_APPROVAL|ACTION_CREATED|RESOLVED|REJECTED`
```json
{ "discrepancy_id":"fad_1","discrepancy_type":"MISSING","severity":"HIGH","route_action":"CREATE_RMA_RECOVERY","status":"ACTION_CREATED","routed_ref_type":"OSR_RMA" }
{ "discrepancy_id":"fad_2","discrepancy_type":"WRONG_SERIAL","severity":"MEDIUM","route_action":"REQUEST_OSR_CORRECTION","status":"PENDING_APPROVAL","approval_request_id":"appr_3" }
{ "discrepancy_id":"fad_3","discrepancy_type":"DAMAGED","severity":"MEDIUM","route_action":"CREATE_TICKET","status":"ACTION_CREATED","routed_ref_type":"TICKET" }
{ "discrepancy_id":"fad_4","discrepancy_type":"FOUND_EXTRA","severity":"LOW","route_action":"NO_ACTION","status":"RESOLVED" }
```
**Reading:** expected-vs-observed raises a typed discrepancy; `rules.field_audit.*` sets `severity` +
`route_action`. **Risky** routes (`REQUEST_OSR_CORRECTION`/`REQUEST_WRITE_OFF`) go `PENDING_APPROVAL`
(EM-CFG-04); safe ones route straight (TICKET/RMA); `NO_ACTION` self-resolves. FA never mutates OSR
itself — it emits the routed action for the owning module.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `WorkOrderService` | lifecycle: `create`/`assign`/**`autoAssign`** (contractor capacity else staff)/`start`/`finalize`/`cancel`; SLA at create |
| `SupportFlowService` / `ShiftingFlowService` | WO-01-FLOW orchestration |
| `FieldAuditCampaignService` | FA campaigns/tasks/observations; rules-driven severity+route; EM-CFG-04 on risky routes |
| `FieldAuditService` | simpler field-audit submission |

## 4. API surface
`/api/work-orders` (`workorder.assign` + **`scope:TECH_REGION`** + idempotency), assign/auto-assign/
start/finalize/cancel/notes/attachments; `…/support-flow` & `…/shifting-flow`; `/api/field-audit-*`.

## 5. Integration (events) — topic `workorder.field`
- **Emits:** `WorkOrder{Created,Assigned,Started,FinalizationPending,Finalized,Reassigned,Cancelled}`,
  `WorkOrder{Support,Shifting}Completed`, `WorkOrderEscalationCandidate`, `FieldAuditWorkOrderRequested`,
  FA discrepancy events.
- **Consumes:** `CreateFieldAuditWorkOrder` (FA → build the WO-01 work order).
- **Downstream:** Fulfillment/Subscription resume on `WorkOrderFinalized`; Workforce releases capacity;
  Ticketing resolves; Reporting counts.

## 6. Processes
Support + shifting flows (process definitions) with the handlers in §1.3/1.4.

## 7. Policy & config
Job-type catalog, flow config, finalization requirements, SLA-by-priority; FA routing via
`rules.field_audit.<type>.discrepancy`.

## 8. Cross-module dependencies
- **Calls →** Workforce (capacity for auto-assign), Foundation Approvals (FA risky routes).
- **Called by →** Fulfillment (install), OSR (swap), Subscription (shifting); Workforce reacts to WO
  lifecycle.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| WO-01 §3 | only allowed status transitions | `WorkOrderService::transition` |
| EM-02 §5.1/5.2 | auto-assign atomically commits contractor capacity | `autoAssign` + Workforce |
| FA risky route | OSR correction / write-off gated by EM-CFG-04 | `FieldAuditCampaignService::route` |
| EM-CFG-03 §8.5 | WO create is TECH_REGION scope-gated | `scope` middleware |

## 10. Open items / deltas
- `FieldAuditWorkOrderRequested` now has a consumer — earlier orphan, fixed.
- WO geolocation (lat/long) + per-technician performance metric are additive (optional backlog).
