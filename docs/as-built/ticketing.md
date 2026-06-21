# Ticketing — As-Built Design

> **Capability codes:** TCK (tickets + SLA), ASR (service requests) · **Module path:**
> `Modules/Ticketing` · **Source-of-truth tests:** `TicketApiTest`, `AsrTest`

## 1. Purpose & boundaries
- **Owns:** customer **tickets** (categories, SLA, comments, timeline) and **ASRs** (advanced service
  requests that spawn work).
- **Does NOT own:** the field work (WorkOrder) — it links to it and resolves when it finishes.
- **Job:** capture, categorise, SLA-track and resolve customer issues; turn an ASR into a work order.

## 📖 Scenarios — read these first

### Scenario A — a support ticket that needs a truck roll
1. **Request:** `POST /api/tickets` `{category:"NO_SIGNAL", subscription_id:"sub_1"}` → ticket `OPEN`,
   SLA due-time stamped from the `sla_policy` for that category.
2. Agent escalates to field: `POST /api/tickets/{id}/work-orders` creates a WO-01 support WO linked
   back (`source_type=TICKET`). Ticket → `IN_PROGRESS`.
3. The WO is finalized → `WorkOrderFinalized` → `ResolveTicketOnWorkOrderFinalized` closes the ticket.
- **Proven by:** `TicketApiTest`.

### Scenario B — an ASR (new-service request)
- `POST /api/tickets/asr` (idempotent) captures a structured service request; `AsrService` validates
  it and emits the request for downstream fulfillment.
- **Proven by:** `AsrTest`.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `ticket` | the issue: category, status, priority, SLA timestamps, links | SLA from policy at create |
| `ticket_category` / `sla_policy` | operator catalog + SLA matrix | config |
| `ticket_comment` / `ticket_timeline` | conversation + audit | append-only timeline |

## 3. Services
| Service | Responsibility |
| --- | --- |
| `TicketService` | ticket lifecycle (create/assign/comment/resolve), SLA capture, WO linkage |
| `AsrService` | advanced service request capture/validation |

## 4. API surface
`/api/tickets` (+ `/{id}/assign`, `/comments`, `/attachments`, `/work-orders`, `/resolve`),
`/api/tickets/asr`, `/api/sla-policies`. Guarded by `permission:ticketing.*`; creates idempotent.

## 5. Integration (events)
- **Emits:** `TicketCreated`, `TicketResolved`, ASR events.
- **Consumes:** `WorkOrderFinalized` → resolve the linked ticket.

## 6. Processes
Mostly service-level; ASR may start a fulfillment/WO flow.

## 7. Policy & config
`ticket_category` + `sla_policy` (operator SLA matrix), per-category routing — all data.

## 8. Cross-module dependencies
- **Calls →** WorkOrder (support/field WO from a ticket).
- **Reacts to →** WorkOrder finalize (auto-resolve); Notification for customer updates.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| SLA-on-create | ticket SLA due-time captured from the category's policy | `TicketService` |
| WO-resolve | a finalized linked WO resolves its ticket | `ResolveTicketOnWorkOrderFinalized` |

## 10. Open items / deltas
- Unified interaction timeline across modules (CUST-INT-01) is a projection opportunity (additive).
