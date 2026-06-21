# Workforce — As-Built Design (EM-02 Capacity)

> **Capability codes:** EM-02 §3.x (contractor capacity & coverage) · **Module path:**
> `Modules/Workforce` · **Tests:** `WorkforceApi`, `SlotCommitmentLifecycle`

## 1. Purpose & boundaries
- **Owns:** the **field capacity** model — contractors, teams, staff, availability slots, region/skill
  coverage, and the **slot-commitment** ledger that reserves capacity for a work order.
- **Does NOT own:** the work (WorkOrder). It answers "who can do this job, where, with spare capacity"
  and atomically books it.
- **Job:** capacity registry + atomic commit/consume/release tied to WO lifecycle.

## 📖 Scenarios (service + Foundation involvement)

### 1. Register a contractor with slots + skills
`POST /api/contractors`, `…/availability-slots`, `…/region-skills` seed a contractor's coverage,
skills and bookable windows (`max_concurrent`). *Operator config.*

### 2. Match + atomically commit capacity for a WO
WorkOrder `autoAssign` calls `ContractorAvailabilityService::commit(slot, wo_id, when)` — atomically
reserves one of the slot's `max_concurrent` places, writing a `contractor_slot_commitment` (`ACTIVE`).
*Foundation: DB transaction guarantees no over-booking.* *Proven by `WorkforceApiTest`.*

### 3. Slot full → no commit → caller falls back
If the slot is at `max_concurrent`, `commit` returns no hold → WorkOrder tries the next contractor, then
an in-house `StaffMember`. *Shows: capacity as a hard limit driving the dispatch strategy.*

### 4. WO finalized → consume capacity
`WorkOrderFinalized` (outbox) → `ResolveSlotCommitmentOnWoLifecycle` → `consumeForWorkOrder(wo_id)`
flips the commitment `ACTIVE → CONSUMED` (capacity spent). *Foundation: outbox listener.* *Proven by
`SlotCommitmentLifecycleTest`.*

### 5. WO cancelled → release capacity
`WorkOrderCancelled` → `releaseForWorkOrder(wo_id)` flips it `RELEASED` (capacity restored, bookable
again). *Proven by `SlotCommitmentLifecycleTest`.*

### 6. Re-running consume/release is a no-op
A duplicate `WorkOrderFinalized` (event replay) → `consumeForWorkOrder` finds no `ACTIVE` commitment →
0 changes. *Foundation: idempotent reaction.* *Proven by `SlotCommitmentLifecycleTest`.*

### 7. Emergency-only slots
A slot with `emergency_only=true` is only matched for URGENT WOs — keeps routine work off the emergency
window. *Config-driven matching.*

### 8. In-house staff matching
When no contractor fits, the matcher uses a `StaffMember` whose skills cover the job. *Shows: the
two-tier (outsourced → in-house) model.*

## 2. Data model — ≥4 sample rows + readings

### `contractor_availability_slot`
```json
{ "slot_id":"slot_1","contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","service_scope":"INSTALL","day_of_week":"ALL_WEEK","hour_start":"08:00","hour_end":"17:00","max_concurrent":5,"emergency_only":false,"active":true }
{ "slot_id":"slot_2","contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","service_scope":"SUPPORT","day_of_week":"MON","max_concurrent":3,"emergency_only":false,"active":true }
{ "slot_id":"slot_3","contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","service_scope":"INSTALL","max_concurrent":2,"emergency_only":true,"active":true }
{ "slot_id":"slot_4","contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","max_concurrent":1,"active":false }
```
**Reading:** a slot is a bookable window: region + service scope + hours + **`max_concurrent`** (the
capacity number). slot_3 is emergency-only (URGENT WOs only); slot_4 is inactive (not bookable). The
matcher needs region + skill + scope + a free place in `max_concurrent`.

### `contractor_slot_commitment` · `status`: `ACTIVE|CONSUMED|RELEASED`
```json
{ "commitment_id":"sc_1","slot_id":"slot_1","wo_id":"wo_1","status":"ACTIVE","committed_for":"2026-06-22T09:00:00Z" }
{ "commitment_id":"sc_2","slot_id":"slot_1","wo_id":"wo_2","status":"CONSUMED" }
{ "commitment_id":"sc_3","slot_id":"slot_2","wo_id":"wo_5","status":"RELEASED" }
{ "commitment_id":"sc_4","slot_id":"slot_1","wo_id":"wo_7","status":"ACTIVE" }
```
**Reading:** each row is one WO's **hold** on a slot. `ACTIVE` counts against `max_concurrent`; it
becomes `CONSUMED` (WO finalized — capacity used) or `RELEASED` (WO cancelled — capacity freed). With
slot_1 at `max_concurrent:5`, two ACTIVE + one CONSUMED leaves 2 free places.

### `skill_catalog` & `contractor_region_skill` / `contractor_region_scope`
```json
{ "skill_code":"fiber-install","name":"FTTH installation","active":true }
{ "skill_code":"coax-install","name":"HFC installation","active":true }
{ "skill_code":"diagnostics","name":"Fault diagnostics","active":true }
{ "region_skill":{ "contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","skill_code":"fiber-install" } }
```
**Reading:** the **skill catalog** is operator-extensible (EM-02 §3.3) — *distinct from* Catalog's
`TechContractorSkill` (contractor config vs capacity, intentional). A contractor's region-skill rows say
"ctr_9 does fiber installs in Karen", which the auto-assign matcher requires.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `ContractorAvailabilityService` | match (region+skill+spare capacity); `commit()` (atomic), `consume()`/`release()`, `consumeForWorkOrder()`/`releaseForWorkOrder()` (by WO id) |

## 4. API surface
`/api/contractors`, `…/availability-slots`, `…/slot-commitments`, `…/staff` under `permission:workforce.*`.

## 5. Integration (events)
- **Consumes:** `ResolveSlotCommitmentOnWoLifecycle` — `WorkOrderFinalized`→consume, `WorkOrderCancelled`→release.
- **Consumed by →** WorkOrder auto-assign (synchronous capacity query + commit).

## 6. Processes
No BPMN; service + the WO-lifecycle event listener.

## 7. Policy & config
Slots, coverage, skills, teams — operator data; `max_concurrent` is the capacity knob; `emergency_only`
reserves capacity for URGENT work.

## 8. Cross-module dependencies
- **Called by →** WorkOrder (`autoAssign` commits capacity).
- **Reacts to →** WorkOrder finalize/cancel.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| EM-02 §3.6 | a finalized WO **consumes** its slot; a cancelled WO **releases** it | `ResolveSlotCommitmentOnWoLifecycle` |
| atomicity | a commit can't exceed `max_concurrent` | `ContractorAvailabilityService::commit` (DB tx) |
| idempotency | re-running consume/release on resolved commitments is a no-op | `consume/releaseForWorkOrder` |

## 10. Open items / deltas
- Slot-commitment lifecycle (consume/release on WO finalize/cancel) was wired during hardening —
  previously committed capacity leaked. Fixed.
