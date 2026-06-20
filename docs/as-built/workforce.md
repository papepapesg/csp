# Workforce — As-Built Design (EM-02 Capacity)

> **Capability codes:** EM-02 §3.x (contractor capacity & coverage) · **Module path:**
> `Modules/Workforce` · **Source-of-truth tests:** `Modules/Workforce/tests/Feature/*`
> (WorkforceApi, SlotCommitmentLifecycle)

## 1. Purpose & boundaries
- **Owns:** the **field capacity** model — contractors, teams, staff, availability slots, region/skill
  coverage, and the **slot-commitment** ledger that reserves capacity for a work order.
- **Does NOT own:** the work itself (WorkOrder). It answers "who can do this job, where, with spare
  capacity" and atomically books it.
- **Job:** capacity registry + atomic commit/consume/release tied to WO lifecycle.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `contractor` / `contractor_team` / `staff_member` | the workforce | operator-scoped |
| `contractor_availability_slot` | bookable capacity windows (region, scope, hours, `max_concurrent`) | capacity = max_concurrent |
| `contractor_slot_commitment` | a WO's hold on a slot: ACTIVE → CONSUMED / RELEASED | one row per WO booking |
| `contractor_region_scope` / `contractor_region_skill` | coverage + skills per region | drives matching |
| `skill_catalog` | EM-02 §3.3 operator-extensible skills | **distinct** from Catalog's `TechContractorSkill` (capacity vs contractor config — intentional) |

## 3. Services
| Service | Responsibility |
| --- | --- |
| `ContractorAvailabilityService` | match a contractor with region+skills+**spare capacity**; `commit()` (atomic hold), `consume()`/`release()`, and `consumeForWorkOrder()` / `releaseForWorkOrder()` (by WO id) |

## 4. API surface
`/api/contractors`, `…/availability-slots`, `…/slot-commitments` (commit/consume/release) under
`permission:workforce.*`; reads `workforce.read`.

## 5. Integration (events)
- **Consumes (`OutboxEventPublished`):** `ResolveSlotCommitmentOnWoLifecycle` — `WorkOrderFinalized`
  → `consumeForWorkOrder` (capacity used), `WorkOrderCancelled` → `releaseForWorkOrder` (capacity
  restored). Mirrors OSR's reservation lifecycle.
- **Consumed by →** WorkOrder auto-assign (synchronous capacity query + commit).

## 6. Processes
No BPMN; pure service + the WO-lifecycle event listener.

## 7. Policy & config
Slots, coverage, skills, teams — all per-operator data; `max_concurrent` is the capacity knob.

## 8. Cross-module dependencies
- **Called by →** WorkOrder (`autoAssign` commits capacity at assignment).
- **Reacts to →** WorkOrder finalize/cancel (consume/release).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| EM-02 §3.6 | a finalized WO **consumes** its slot capacity; a cancelled WO **releases** it | `ResolveSlotCommitmentOnWoLifecycle` |
| (atomicity) | capacity commit is atomic against `max_concurrent` | `ContractorAvailabilityService::commit` |
| (idempotency) | re-running consume/release on resolved commitments is a no-op | `consumeForWorkOrder`/`releaseForWorkOrder` |

## 10. Open items / deltas
- Slot-commitment lifecycle (consume/release on WO finalize/cancel) was wired during hardening —
  previously committed capacity leaked. Fixed.
