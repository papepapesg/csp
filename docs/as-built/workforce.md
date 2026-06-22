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

The whole module is one idea worth holding in your head before the scenarios: a **slot** is a recurring
window where some contractor can do work, and `max_concurrent` is how many jobs that window can hold at
once. Booking a job into a slot writes a **commitment** (a hold). Here is the life of one hold:

```mermaid
flowchart LR
    R["reserve<br/>commit writes contractor_slot_commitment ACTIVE"] --> A["ACTIVE<br/>counts against max_concurrent"]
    A -->|"WorkOrderFinalized"| C["CONSUMED<br/>capacity spent"]
    A -->|"WorkOrderCancelled"| RL["RELEASED<br/>capacity freed"]
    A -->|"hold never actioned, swept"| E["EXPIRED"]
```

### 1. Register a contractor + teams + staff

**The story:** Someone in the back office is setting up who can do field work. They add an outside
company, its crews, and the individual technicians. Separately, an operator pre-loads where each
contractor works, what they are qualified for, and which hours they are free — this part is data the
operator seeds, not something you click through an API.

**Who does what:**
- `POST /api/contractors`, `…/{contractor}/teams`, and `POST /api/staff` write the contractor registry
  (the companies, crews, and technicians).
- The coverage rows — `contractor_availability_slot`, `contractor_region_skill`,
  `contractor_region_scope`, `skill_catalog` — are **operator seed data with no write API**. They define
  the bookable windows (`max_concurrent`), the certifications, and the region coverage.
- *Operator config.*

### 2. Match + atomically commit capacity for a WO

**The story:** A work order needs a field tech. The system finds a contractor who covers that area, has
the right skill, and still has a free spot in their schedule — then books that spot for this job, all in
one safe step so two jobs can never grab the same last place.

**Who does what:**
- WorkOrder's `autoAssign` calls `ContractorAvailabilityService::resolve(...)` to rank candidates by
  region + skill + spare capacity.
- It then calls `commit(slot, wo_id, when)`, which atomically reserves one of the slot's `max_concurrent`
  places and writes a `contractor_slot_commitment` row with status `ACTIVE`.
- *Foundation: a DB transaction guarantees no over-booking.* *Proven by `WorkforceApiTest`.*

**Sample —** the hold this writes (one place taken in a slot that allows five):
```json
{ "commitment_id":"sc_1","slot_id":"slot_1","wo_id":"wo_1","qty":1,"status":"ACTIVE" }
```
Slot `slot_1` has `max_concurrent:5`, so after this commit four places remain.

### 3. Slot full → no commit → caller falls back

**The story:** The best contractor's schedule is already full. Rather than overbook them, the system
quietly moves on to the next-best option, and if no outside contractor has room, it uses an in-house
technician.

**Who does what:**
- If the slot is already at `max_concurrent`, `commit` returns no hold.
- WorkOrder then tries the next contractor, and finally an in-house `StaffMember`.
- *Shows: capacity is a hard limit that drives the dispatch strategy.*

### 4. WO finalized → consume capacity

**The story:** The job is done and signed off. The hold that was reserving a spot now turns into "spot
used" — that place is spent, not given back.

**Who does what:**
- The `WorkOrderFinalized` event (via the outbox) reaches `ResolveSlotCommitmentOnWoLifecycle`.
- That listener calls `consumeForWorkOrder(wo_id)`, flipping the commitment `ACTIVE → CONSUMED` (capacity
  spent).
- *Foundation: an outbox listener.* *Proven by `SlotCommitmentLifecycleTest`.*

### 5. WO cancelled → release capacity

**The story:** The job got cancelled, so the spot it was holding should go back into the pool for someone
else to book.

**Who does what:**
- The `WorkOrderCancelled` event reaches the same listener, which calls `releaseForWorkOrder(wo_id)`.
- That flips the commitment to `RELEASED` (capacity restored, the place is bookable again).
- *Proven by `SlotCommitmentLifecycleTest`.*

### 6. Re-running consume/release is a no-op

**The story:** Sometimes the same "job finished" message arrives twice. The second time should change
nothing — no double-counting, no errors.

**Who does what:**
- A duplicate `WorkOrderFinalized` (event replay) calls `consumeForWorkOrder` again.
- It finds no `ACTIVE` commitment for that work order, so it makes 0 changes.
- *Foundation: an idempotent reaction.* *Proven by `SlotCommitmentLifecycleTest`.*

### 7. Emergency-only slots

**The story:** Some contractor windows are reserved strictly for emergencies. Routine jobs are never
booked into them, so there is always room when something urgent comes in.

**Who does what:**
- A slot with `emergency_only=true` is only matched for URGENT work orders.
- This keeps routine work off the emergency window.
- *Config-driven matching.*

### 8. In-house staff matching

**The story:** When no outside contractor can take the job, the system falls back to the operator's own
employees, picking one whose skills fit.

**Who does what:**
- When no contractor fits, the matcher uses a `StaffMember` whose skills cover the job.
- *Shows: the two-tier (outsourced → in-house) model.*

## 2. Data model — ≥4 **complete** sample rows + readings
> **Completeness:** each row lists **every domain column** (nullables shown as `null`). The string
> business / composite key shown is the real primary key; `created_at`/`updated_at` are omitted by
> convention.

### `contractor` (the registry root) · `type`: `INTERNAL|EXTERNAL` · `status`: `ACTIVE|SUSPENDED|RETIRED`
```json
{ "contractor_id":"ctr_9","operator_code":"WIK","code":"FIBERCO","name":"FiberCo Ltd","type":"EXTERNAL","skills":["fiber-install","diagnostics"],"status":"ACTIVE" }
{ "contractor_id":"ctr_4","operator_code":"WIK","code":"COASTNET","name":"CoastNet Engineers","type":"EXTERNAL","skills":["coax-install"],"status":"ACTIVE" }
{ "contractor_id":"ctr_1","operator_code":"WIK","code":"INHOUSE","name":"WIK In-House Field","type":"INTERNAL","skills":null,"status":"ACTIVE" }
{ "contractor_id":"ctr_0","operator_code":"WIK","code":"OLDCO","name":"OldCo (retired)","type":"EXTERNAL","skills":null,"status":"RETIRED" }
```
**Reading:** a contractor is who a job gets dispatched to.
- `type` splits OUTSOURCED (`EXTERNAL`) from in-house (`INTERNAL`).
- `status` retires one without deleting it.
- `code` is the operator-unique short handle (the pair `(operator,code)` is unique).
- The real per-(contractor, region, skill) certification lives in `contractor_region_skill`; the `skills`
  JSON here is only a coarse summary.

### `contractor_team` · `status`: `ACTIVE|…` · & `staff_member` · `role`: `TECHNICIAN|TEAM_LEAD|SUPERVISOR`
```json
{ "team_id":"team_2","contractor_id":"ctr_4","operator_code":"WIK","code":"NYALI-A","name":"Nyali Crew A","skills":["coax-install"],"status":"ACTIVE" }
{ "staff_id":"stf_7","operator_code":"WIK","contractor_id":null,"team_id":null,"name":"Asha Mwangi","role":"TECHNICIAN","msisdn":"+254700000007","skills":["diagnostics"],"status":"ACTIVE" }
{ "staff_id":"stf_9","operator_code":"WIK","contractor_id":"ctr_4","team_id":"team_2","name":"Juma Otieno","role":"TEAM_LEAD","msisdn":"+254700000009","skills":["coax-install"],"status":"ACTIVE" }
{ "staff_id":"stf_3","operator_code":"WIK","contractor_id":"ctr_9","team_id":null,"name":"Wanjiru Kamau","role":"TECHNICIAN","msisdn":null,"skills":["fiber-install"],"status":"ACTIVE" }
```
**Reading:** a `contractor_team` (`team_id`) is a crew that belongs to a contractor; a `staff_member`
(`staff_id`) is a single named technician.
- A staff member with **no `contractor_id`** is **in-house** (stf_7) — the second tier the matcher falls
  back to when no outsourced contractor has capacity.
- A staff member with a `contractor_id`/`team_id` is that crew's tech.
- `skills` drives skill matching; `role` ranks people within a team.

### `contractor_availability_slot` · `day_of_week`: `MONDAY..SUNDAY|ALL_WEEK` · `service_scope`: `INSTALL|SUPPORT|MAINTENANCE|RECOVERY|AUDIT`
```json
{ "slot_id":"slot_1","operator_code":"WIK","contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","service_scope":"INSTALL","day_of_week":"ALL_WEEK","hour_start":"08:00:00","hour_end":"17:00:00","timezone":"Africa/Nairobi","max_concurrent":5,"emergency_only":false,"active":true,"effective_from":"2026-01-01","effective_to":null }
{ "slot_id":"slot_2","operator_code":"WIK","contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","service_scope":"SUPPORT","day_of_week":"MONDAY","hour_start":"09:00:00","hour_end":"13:00:00","timezone":"Africa/Nairobi","max_concurrent":3,"emergency_only":false,"active":true,"effective_from":null,"effective_to":null }
{ "slot_id":"slot_3","operator_code":"WIK","contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","service_scope":"INSTALL","day_of_week":"ALL_WEEK","hour_start":"00:00:00","hour_end":"23:59:00","timezone":"Africa/Nairobi","max_concurrent":2,"emergency_only":true,"active":true,"effective_from":"2026-03-01","effective_to":null }
{ "slot_id":"slot_4","operator_code":"WIK","contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","service_scope":"SUPPORT","day_of_week":"SUNDAY","hour_start":"08:00:00","hour_end":"12:00:00","timezone":"Africa/Nairobi","max_concurrent":1,"emergency_only":false,"active":false,"effective_from":"2026-01-01","effective_to":"2026-05-31" }
```
**Reading:** a slot is a bookable window defined by region + service scope + a day/hour range (in
`timezone`) + **`max_concurrent`** (the capacity number — how many jobs the window holds at once).
- slot_3 is emergency-only (`emergency_only:true`), so it matches URGENT WOs only.
- slot_4 is not bookable: it is `active:false` and its `effective_to` date has already lapsed.
- To match a slot, the matcher needs region + skill + scope + a free place in `max_concurrent`, all inside
  the slot's effective window.

### `contractor_slot_commitment` · `status`: `ACTIVE|CONSUMED|RELEASED|EXPIRED`
```json
{ "commitment_id":"sc_1","operator_code":"WIK","slot_id":"slot_1","contractor_id":"ctr_9","wo_id":"wo_1","committed_for_datetime":"2026-06-22T09:00:00Z","qty":1,"status":"ACTIVE","consumed_at":null,"released_at":null,"expired_at":null }
{ "commitment_id":"sc_2","operator_code":"WIK","slot_id":"slot_1","contractor_id":"ctr_9","wo_id":"wo_2","committed_for_datetime":"2026-06-20T10:00:00Z","qty":1,"status":"CONSUMED","consumed_at":"2026-06-20T12:30:00Z","released_at":null,"expired_at":null }
{ "commitment_id":"sc_3","operator_code":"WIK","slot_id":"slot_2","contractor_id":"ctr_9","wo_id":"wo_5","committed_for_datetime":"2026-06-21T11:00:00Z","qty":1,"status":"RELEASED","consumed_at":null,"released_at":"2026-06-21T11:45:00Z","expired_at":null }
{ "commitment_id":"sc_4","operator_code":"WIK","slot_id":"slot_1","contractor_id":"ctr_9","wo_id":"wo_7","committed_for_datetime":"2026-06-22T14:00:00Z","qty":1,"status":"EXPIRED","consumed_at":null,"released_at":null,"expired_at":"2026-06-22T15:00:00Z" }
```
**Reading:** each row is one work order's **hold** on a slot, taking `qty` places out of the slot's
`max_concurrent`. The `status` walks a short lifecycle:
- `ACTIVE` — the hold is live and **counts against capacity**.
- `CONSUMED` — the WO finalized, the place is **used up** (`consumed_at` stamped).
- `RELEASED` — the WO was cancelled, the place is **freed** (`released_at` stamped).
- `EXPIRED` — the hold was never actioned and got **swept** (`expired_at` stamped).

Only `ACTIVE` rows still count. So with `slot_1` at `max_concurrent:5`, having one ACTIVE (sc_1) + one
CONSUMED (sc_2) + one EXPIRED (sc_4) leaves room for new bookings — only sc_1 occupies a place. The
matching `*_at` timestamp records which terminal transition fired.

```mermaid
stateDiagram-v2
    [*] --> ACTIVE: commit
    ACTIVE --> CONSUMED: WorkOrderFinalized
    ACTIVE --> RELEASED: WorkOrderCancelled
    ACTIVE --> EXPIRED: hold swept
    CONSUMED --> [*]
    RELEASED --> [*]
    EXPIRED --> [*]
```

### `skill_catalog` (operator-extensible, EM-02 §3.3) · composite PK `(operator_code, skill_code)` · `category`: `TECHNICAL_INSTALL|TECHNICAL_SUPPORT|SOFT_SKILL|…`
```json
{ "operator_code":"WIK","skill_code":"fiber-install","display_name":"FTTH installation","category":"TECHNICAL_INSTALL","active":true }
{ "operator_code":"WIK","skill_code":"coax-install","display_name":"HFC installation","category":"TECHNICAL_INSTALL","active":true }
{ "operator_code":"WIK","skill_code":"diagnostics","display_name":"Fault diagnostics","category":"TECHNICAL_SUPPORT","active":true }
{ "operator_code":"WIK","skill_code":"vip-handling","display_name":"VIP customer handling","category":"SOFT_SKILL","active":false }
```
**Reading:** the **skill catalog** is operator-extensible — and it is deliberately *distinct from*
Catalog's `TechContractorSkill` (that is contractor config; this is capacity). `category` groups skills;
`active:false` (vip-handling) retires a code without deleting it.

### `contractor_region_skill` (the WO routing filter, EM-02 §3.4) · composite PK `(contractor_id, tech_region_id, skill_code)`
```json
{ "contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","operator_code":"WIK","skill_code":"fiber-install","active":true }
{ "contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","operator_code":"WIK","skill_code":"diagnostics","active":true }
{ "contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","operator_code":"WIK","skill_code":"coax-install","active":true }
{ "contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","operator_code":"WIK","skill_code":"fiber-install","active":false }
```
**Reading:** these rows say things like "ctr_9 is certified for fiber installs + diagnostics in Karen" —
exactly what the auto-assign matcher requires before it can pick a contractor. The certification is **per
(contractor, region, skill)**, so ctr_4's fiber-install in Nyali being `active:false` (de-certified) means
ctr_4 will not match for fiber there.

### `contractor_region_scope` (per-region service coverage, EM-02 §3.2) · `service_scope`: `INSTALL|SUPPORT|MAINTENANCE|RECOVERY|AUDIT` · `coverage_role`: `PRIMARY|BACKUP|EXCLUSIVE`
```json
{ "coverage_id":"cov_1","operator_code":"WIK","contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","service_scope":"INSTALL","coverage_role":"PRIMARY","effective_from":"2026-01-01","effective_to":null }
{ "coverage_id":"cov_2","operator_code":"WIK","contractor_id":"ctr_9","tech_region_id":"KE-NRB-KAREN","service_scope":"SUPPORT","coverage_role":"PRIMARY","effective_from":"2026-01-01","effective_to":null }
{ "coverage_id":"cov_3","operator_code":"WIK","contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","service_scope":"INSTALL","coverage_role":"PRIMARY","effective_from":"2026-03-01","effective_to":null }
{ "coverage_id":"cov_4","operator_code":"WIK","contractor_id":"ctr_4","tech_region_id":"KE-MSA-NYALI","service_scope":"RECOVERY","coverage_role":"BACKUP","effective_from":"2026-03-01","effective_to":"2026-06-30" }
```
**Reading:** coverage is **time-versioned** (`effective_from`/`effective_to`) and scoped per
(contractor, region, service_scope). `coverage_role` ranks contractors (PRIMARY first, BACKUP as
fallback, EXCLUSIVE locks the region); cov_4 is a BACKUP recovery coverage that lapses end of June.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `ContractorAvailabilityService` | `resolve()` (rank contractors by region+skill+spare capacity), `remainingCapacity()`; `commit()` (atomic), `consume()`/`release()`, `consumeForWorkOrder()`/`releaseForWorkOrder()` (by WO id) |

## 4. API surface
`/api/contractors` (+`…/{contractor}/teams`), `/api/staff`, `/api/contractor-availability` (capacity query),
`/api/contractor-slot-commitments` (+`…/{id}/consume`, `DELETE …/{id}` = release). Reads under
`permission:workforce.read`; writes under `permission:workforce.manage` (+ `idempotency` on commit/consume/release).

## 5. Integration (events)
- **Emits** (topic `em.cs`): `ContractorSlotCommitment{Created,Consumed,Released}` on commit/consume/release.
- **Consumes:** `ResolveSlotCommitmentOnWoLifecycle` (listener on `OutboxEventPublished`, matched by `event_type`)
  — `WorkOrderFinalized`→consume, `WorkOrderCancelled`→release.
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
