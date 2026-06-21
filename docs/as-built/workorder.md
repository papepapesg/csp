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
`finalize` is rejected unless the `wo_finalization_requirements` items are satisfied (R-WO finalize gate).

## 2. Data model — ≥4 **complete** sample rows + readings
> **Completeness:** each row lists **every domain column** (nullables shown as `null`). The string
> business key shown is the real primary key; `created_at`/`updated_at` are omitted by convention.

### `work_order` · `type`: `INSTALLATION|SUPPORT|SHIFTING|RELOCATION|EQUIPMENT|NOC|FIELD_AUDIT` · `status`: `PENDING|ASSIGNED|IN_PROGRESS|FINALIZATION_PENDING|COMPLETED|CANCELLED` · `priority`: `LOW|NORMAL|HIGH|URGENT` · `source_type`: `TICKET|SUBSCRIPTION_OP|FULFILLMENT|MANUAL|FIELD_AUDIT` · `link_type`: `PARENT_CHILD|DEPENDENCY`
```json
{ "work_order_id":"wo_1","operator_code":"WIK","type":"INSTALLATION","kind":null,"job_type_code":"FTTH_INSTALL","current_phase":null,"status":"COMPLETED","priority":"NORMAL","account_id":"acct_50","subscription_id":"sub_1","customer_id":"cust_50","homepass_id":"hp_1","tech_region_id":"KE-NRB-KAREN","contractor_id":"ctr_9","team_id":null,"assigned_technician_id":"tech_3","source_type":"FULFILLMENT","source_ref":"order_1","master_wo_id":null,"originating_context_type":null,"initial_reason":null,"escalation_candidate":false,"link_type":null,"scheduled_at":"2026-06-20T08:00:00Z","sla_due_at":"2026-06-21T08:00:00Z","assigned_at":"2026-06-20T07:30:00Z","first_response_at":"2026-06-20T08:05:00Z","started_at":"2026-06-20T09:00:00Z","finalized_at":"2026-06-20T10:30:00Z","warranty_until":"2026-09-18T00:00:00Z","resolution_code":"INSTALL_OK","final_reason":null,"required_skills":["fiber-install"],"findings":{"ontSerial":"SN-001"},"created_by":"u_desk1" }
{ "work_order_id":"wo_2","operator_code":"WIK","type":"SUPPORT","kind":"SUPPORT","job_type_code":"NO_SIGNAL","current_phase":"SITE_VISIT","status":"IN_PROGRESS","priority":"URGENT","account_id":"acct_51","subscription_id":"sub_2","customer_id":"cust_51","homepass_id":"hp_2","tech_region_id":"KE-NRB-KAREN","contractor_id":null,"team_id":null,"assigned_technician_id":"staff_7","source_type":"TICKET","source_ref":"tkt_9","master_wo_id":null,"originating_context_type":"TICKET","initial_reason":"no signal at ONT","escalation_candidate":false,"link_type":null,"scheduled_at":"2026-06-21T10:00:00Z","sla_due_at":"2026-06-21T14:00:00Z","assigned_at":"2026-06-21T09:30:00Z","first_response_at":"2026-06-21T10:10:00Z","started_at":"2026-06-21T10:15:00Z","finalized_at":null,"warranty_until":null,"resolution_code":null,"final_reason":null,"required_skills":["diagnostics"],"findings":null,"created_by":"u_desk2" }
{ "work_order_id":"wo_3","operator_code":"WIK","type":"SHIFTING","kind":"SHIFTING","job_type_code":null,"current_phase":"PHASE_1","status":"ASSIGNED","priority":"NORMAL","account_id":"acct_52","subscription_id":"sub_3","customer_id":"cust_52","homepass_id":"hp_3","tech_region_id":"KE-MSA-NYALI","contractor_id":"ctr_4","team_id":"team_2","assigned_technician_id":null,"source_type":"SUBSCRIPTION_OP","source_ref":"subop_77","master_wo_id":null,"originating_context_type":null,"initial_reason":null,"escalation_candidate":false,"link_type":null,"scheduled_at":"2026-06-23T08:00:00Z","sla_due_at":"2026-06-30T08:00:00Z","assigned_at":"2026-06-21T12:00:00Z","first_response_at":null,"started_at":null,"finalized_at":null,"warranty_until":null,"resolution_code":null,"final_reason":null,"required_skills":["fiber-install"],"findings":null,"created_by":"u_desk1" }
{ "work_order_id":"wo_4","operator_code":"WIK","type":"FIELD_AUDIT","kind":"FIELD_AUDIT","job_type_code":null,"current_phase":null,"status":"PENDING","priority":"LOW","account_id":null,"subscription_id":null,"customer_id":"cust_60","homepass_id":null,"tech_region_id":"KE-NRB-KAREN","contractor_id":null,"team_id":null,"assigned_technician_id":null,"source_type":"FIELD_AUDIT","source_ref":"fat_1","master_wo_id":null,"originating_context_type":null,"initial_reason":null,"escalation_candidate":false,"link_type":null,"scheduled_at":null,"sla_due_at":"2026-06-28T08:00:00Z","assigned_at":null,"first_response_at":null,"started_at":null,"finalized_at":null,"warranty_until":null,"resolution_code":null,"final_reason":null,"required_skills":[],"findings":null,"created_by":"u_audit1" }
{ "work_order_id":"wo_5","operator_code":"WIK","type":"SUPPORT","kind":"SUPPORT","job_type_code":"QCS","current_phase":"ESCALATED","status":"CANCELLED","priority":"HIGH","account_id":"acct_53","subscription_id":"sub_4","customer_id":"cust_53","homepass_id":"hp_4","tech_region_id":"KE-NRB-KAREN","contractor_id":"ctr_9","team_id":null,"assigned_technician_id":"tech_3","source_type":"SUBSCRIPTION_OP","source_ref":"subop_88","master_wo_id":"wo_2","originating_context_type":"TICKET","initial_reason":"repeat fault — quality control","escalation_candidate":true,"link_type":"PARENT_CHILD","scheduled_at":null,"sla_due_at":"2026-06-22T00:00:00Z","assigned_at":"2026-06-21T13:00:00Z","first_response_at":null,"started_at":null,"finalized_at":null,"warranty_until":null,"resolution_code":null,"final_reason":"CANCELLED_BY_DESK","required_skills":["diagnostics"],"findings":null,"created_by":"u_desk2" }
```
**Reading:** `type`/`kind` pick the flow + skills (`required_skills` filters auto-assign); `source_type/ref`
is the **origin** (a fulfillment order, a ticket, a subscription op, a field-audit task) — the back-link
other modules resume on. `priority` sets the SLA window at create (`sla_due_at`: URGENT=4h … LOW=168h),
`first_response_at` is the SLA first-touch. `assigned_*`/`started_at`/`finalized_at` track the lifecycle;
`current_phase` is the flow cursor for SUPPORT/SHIFTING. wo_5 is an escalation child (`master_wo_id`
+`link_type=PARENT_CHILD`, `escalation_candidate=true`) — a QCS WO spawned off wo_2. `warranty_until`
links a finalized install to its warranty window; `findings` captures close-out evidence (the ONT serial).

### `wo_job_type_catalog` (operator job-type config) · `kind`: `SUPPORT|SHIFTING|INSTALLATION` · `network_type`: `GPON|HFC|…`
```json
{ "id":"jtc_1","operator_code":"WIK","job_type_code":"FTTH_INSTALL","kind":"INSTALLATION","display_name":"FTTH Installation","description":"GPON new install","network_type":"GPON","requires_site_visit":true,"warranty_days":90 }
{ "id":"jtc_2","operator_code":"WIK","job_type_code":"HFC_INSTALL","kind":"INSTALLATION","display_name":"HFC Installation","description":null,"network_type":"HFC","requires_site_visit":true,"warranty_days":90 }
{ "id":"jtc_3","operator_code":"WIK","job_type_code":"NO_SIGNAL","kind":"SUPPORT","display_name":"No Signal Diagnostics","description":"loss-of-service fault","network_type":null,"requires_site_visit":true,"warranty_days":30 }
{ "id":"jtc_4","operator_code":"WIK","job_type_code":"RPT","kind":"SUPPORT","display_name":"Remote Password/Profile Tweak","description":"desk-only fix","network_type":null,"requires_site_visit":false,"warranty_days":30 }
```
**Reading:** the **job-type catalog** is per-operator config — `requires_site_visit` drives the
site-visit-decision gateway (jtc_4 is desk-only), `warranty_days` feeds warranty linkage, and the code
drives skill-matching for auto-assign. *(The earlier `required_skills`/`sla_hours` sample columns were a
doc shorthand and don't exist on this table — required skills live on `work_order.required_skills`, SLA
is computed from priority.)*

### `wo_finalization_requirements` (the close-out checklist) · `kind`: `INSTALLATION|SUPPORT|SHIFTING`
```json
{ "id":"finr_1","operator_code":"WIK","kind":"INSTALLATION","job_type_code":"FTTH_INSTALL","required_note_kinds":["findings","ont_serial"],"required_attachment_categories":["ont_photo","speedtest"],"min_attachments_per_category":{"ont_photo":1,"speedtest":1} }
{ "id":"finr_2","operator_code":"WIK","kind":"INSTALLATION","job_type_code":null,"required_note_kinds":["findings"],"required_attachment_categories":["site_photo"],"min_attachments_per_category":{"site_photo":1} }
{ "id":"finr_3","operator_code":"WIK","kind":"SUPPORT","job_type_code":null,"required_note_kinds":["findings","solution","final_reason_set"],"required_attachment_categories":null,"min_attachments_per_category":null }
{ "id":"finr_4","operator_code":"WIK","kind":"SHIFTING","job_type_code":null,"required_note_kinds":["findings","bindings_captured"],"required_attachment_categories":["new_premises_photo"],"min_attachments_per_category":{"new_premises_photo":2} }
```
**Reading:** the per-`(operator,kind,job_type_code)` checklist the 2-step finalize enforces at
second-confirm — required structured-note kinds plus required attachment categories and minimum counts.
`job_type_code=null` (finr_2/3/4) is the default for the kind; a job-type-specific row (finr_1) overrides
it (e.g. FTTH must capture the ONT serial + a speedtest before it can complete).

### `field_audit_task` · `audit_type`: `EQUIPMENT|NETWORK|KYC` · `task_type`: `CUSTOMER_PREMISES|FIELD_SITE|POST_SWAP|INVESTIGATION` · `status`: `CREATED|ASSIGNED|IN_PROGRESS|SUBMITTED|DISCREPANCY_OPEN|CLOSED|CANCELLED`
```json
{ "audit_task_id":"fat_1","operator_code":"WIK","campaign_id":"fac_1","audit_type":"EQUIPMENT","task_type":"CUSTOMER_PREMISES","customer_id":"cust_60","account_id":"acct_60","subscription_id":"sub_60","homepass_id":"hp_10","wo_id":null,"assigned_to_user_id":null,"assigned_team_id":null,"status":"CREATED","source_event_ref":"evt_aa1","due_at":"2026-06-28T17:00:00Z","submitted_at":null,"closed_at":null }
{ "audit_task_id":"fat_2","operator_code":"WIK","campaign_id":"fac_1","audit_type":"EQUIPMENT","task_type":"POST_SWAP","customer_id":"cust_61","account_id":"acct_61","subscription_id":"sub_61","homepass_id":"hp_11","wo_id":"wo_4","assigned_to_user_id":"tech_3","assigned_team_id":null,"status":"DISCREPANCY_OPEN","source_event_ref":"evt_aa2","due_at":"2026-06-25T17:00:00Z","submitted_at":"2026-06-24T15:00:00Z","closed_at":null }
{ "audit_task_id":"fat_3","operator_code":"WIK","campaign_id":"fac_2","audit_type":"NETWORK","task_type":"FIELD_SITE","customer_id":null,"account_id":null,"subscription_id":null,"homepass_id":null,"wo_id":null,"assigned_to_user_id":"staff_7","assigned_team_id":"team_2","status":"CLOSED","source_event_ref":"evt_bb1","due_at":"2026-06-20T17:00:00Z","submitted_at":"2026-06-19T12:00:00Z","closed_at":"2026-06-19T16:00:00Z" }
{ "audit_task_id":"fat_4","operator_code":"WIK","campaign_id":null,"audit_type":"KYC","task_type":"CUSTOMER_PREMISES","customer_id":"cust_62","account_id":"acct_62","subscription_id":null,"homepass_id":"hp_12","wo_id":null,"assigned_to_user_id":"staff_9","assigned_team_id":null,"status":"ASSIGNED","source_event_ref":"evt_cc1","due_at":"2026-06-27T17:00:00Z","submitted_at":null,"closed_at":null }
```
**Reading:** one capability, **audit_type-driven** (equipment count, network plant, KYC re-check). A
task moves `CREATED → ASSIGNED → IN_PROGRESS → SUBMITTED → (DISCREPANCY_OPEN | CLOSED)`. `wo_id` set
(fat_2) = WO-backed (a tech is dispatched); otherwise it's a desk/mobile task. `campaign_id` ties the
task to its campaign (fat_4 is an ad-hoc KYC task, no campaign); `source_event_ref` is the idempotency
key on the originating event.

### `field_audit_discrepancy` · `discrepancy_type`: `MISSING|WRONG_SERIAL|FOUND_EXTRA|DAMAGED|WRONG_LOCATION|NOT_ACCESSIBLE` · `severity`: `LOW|MEDIUM|HIGH|CRITICAL` · `route_action`: `CREATE_TICKET|CREATE_RMA_RECOVERY|REQUEST_OSR_CORRECTION|REQUEST_WRITE_OFF|NO_ACTION` · `status`: `OPEN|ROUTED|PENDING_APPROVAL|ACTION_CREATED|RESOLVED|REJECTED|CLOSED`
```json
{ "discrepancy_id":"fad_1","operator_code":"WIK","audit_task_id":"fat_2","expected_item_id":"fae_1","observation_id":"fao_1","discrepancy_type":"MISSING","severity":"HIGH","status":"ACTION_CREATED","route_action":"CREATE_RMA_RECOVERY","routed_ref_type":"OSR_RMA","routed_ref_id":"rma_5","approval_request_id":null,"resolved_at":null }
{ "discrepancy_id":"fad_2","operator_code":"WIK","audit_task_id":"fat_2","expected_item_id":"fae_2","observation_id":"fao_2","discrepancy_type":"WRONG_SERIAL","severity":"MEDIUM","status":"PENDING_APPROVAL","route_action":"REQUEST_OSR_CORRECTION","routed_ref_type":null,"routed_ref_id":null,"approval_request_id":"appr_3","resolved_at":null }
{ "discrepancy_id":"fad_3","operator_code":"WIK","audit_task_id":"fat_2","expected_item_id":"fae_3","observation_id":"fao_3","discrepancy_type":"DAMAGED","severity":"MEDIUM","status":"ACTION_CREATED","route_action":"CREATE_TICKET","routed_ref_type":"TICKET","routed_ref_id":"tkt_44","approval_request_id":null,"resolved_at":null }
{ "discrepancy_id":"fad_4","operator_code":"WIK","audit_task_id":"fat_3","expected_item_id":null,"observation_id":"fao_9","discrepancy_type":"FOUND_EXTRA","severity":"LOW","status":"RESOLVED","route_action":"NO_ACTION","routed_ref_type":null,"routed_ref_id":null,"approval_request_id":null,"resolved_at":"2026-06-19T16:00:00Z" }
```
**Reading:** expected-vs-observed raises a typed discrepancy (linked to its `expected_item_id` +
`observation_id`); `rules.field_audit.*` sets `severity` + `route_action`. **Risky** routes
(`REQUEST_OSR_CORRECTION`/`REQUEST_WRITE_OFF`) go `PENDING_APPROVAL` (EM-CFG-04, `approval_request_id`
set); safe ones route straight (`routed_ref_type`/`routed_ref_id` point at the created TICKET/RMA);
`NO_ACTION` self-resolves (`resolved_at` stamped). FA never mutates OSR itself — it emits the routed
action for the owning module. fad_4 is a `FOUND_EXTRA` with no expected item (an unexpected unit on site).

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
