# Ticketing — As-Built Design

> **Capability codes:** TCK (tickets + SLA), ASR (service requests) · **Module path:**
> `Modules/Ticketing` · **Tests:** `TicketApi`, `Asr`

## 1. Purpose & boundaries
- **Owns:** customer **tickets** (categories, SLA, comments, timeline) and **ASRs** (requests that
  spawn work).
- **Does NOT own:** field work (WorkOrder) — it links to it and resolves when it finishes.
- **Job:** capture, categorise, SLA-track and resolve customer issues; turn an ASR into a work order.

## 📖 Scenarios (service + Foundation involvement)

### 1. Create a ticket (SLA stamped from policy)
`POST /api/tickets {category:'NO_SIGNAL', subscription_id}` → `TicketService::create`: ticket `OPEN`,
SLA due-time stamped from the matching `sla_policy`. Emits `TicketCreated`. *Proven by `TicketApiTest`.*

### 2. Escalate to a truck roll → linked WO
`POST …/{id}/work-orders` creates a WO-01 support WO linked back (`source_type=TICKET`). Ticket
`IN_PROGRESS`. *Cross-module: WorkOrder.* *Proven by `TicketApiTest`.*

### 3. WO finalized → auto-resolve (event-driven)
`WorkOrderFinalized` (outbox) → `ResolveTicketOnWorkOrderFinalized` closes the linked ticket
(`RESOLVED`). *Foundation: outbox listener.*

### 4. Conversation on the timeline
`POST …/{id}/comments` appends a `ticket_comment` + a `ticket_timeline` row (append-only audit).

### 5. ASR — a new-service request (idempotent)
`POST /api/tickets/asr` (idempotent) → `AsrService` validates a structured request + emits it downstream
for fulfillment. *Proven by `AsrTest`.*

### 6. SLA breach surfaces on the NOC
Past-due open tickets appear in ItOps `GET /api/noc/sla-overdue` (reads ticket SLA timestamps).

### 7. Assign to an agent
`POST …/{id}/assign` records the owner; the timeline captures the change.

### 8. Category drives SLA + routing
The `ticket_category` fixes the SLA matrix + default priority — an operator tunes SLAs as data.

## 2. Data model — ≥4 sample rows + readings

### `ticket` · `status`: `OPEN|IN_PROGRESS|RESOLVED|CLOSED` · `priority`: `LOW|NORMAL|HIGH|URGENT`
```json
{ "ticket_id":"tkt_1","category":"NO_SIGNAL","status":"OPEN","priority":"URGENT","subscription_id":"sub_1","sla_due_at":"2026-06-20T16:00:00Z" }
{ "ticket_id":"tkt_2","category":"BILLING_QUERY","status":"IN_PROGRESS","priority":"NORMAL","work_order_id":null }
{ "ticket_id":"tkt_3","category":"NO_SIGNAL","status":"RESOLVED","priority":"HIGH","work_order_id":"wo_2" }
{ "ticket_id":"tkt_4","category":"COMPLAINT","status":"CLOSED","priority":"LOW" }
```
**Reading:** the status is the resolution lifecycle; the SLA `sla_due_at` is stamped at create from the
category policy (URGENT NO_SIGNAL = 4h). tkt_3 was resolved by its linked WO (`wo_2`). A past-due `OPEN`
ticket is what the NOC SLA-overdue view surfaces.

### `ticket_category` & `sla_policy`
```json
{ "category_code":"NO_SIGNAL","default_priority":"URGENT","spawns_work_order":true }
{ "category_code":"BILLING_QUERY","default_priority":"NORMAL","spawns_work_order":false }
{ "sla":{ "category_code":"NO_SIGNAL","priority":"URGENT","response_hours":1,"resolution_hours":4 } }
{ "sla":{ "category_code":"BILLING_QUERY","priority":"NORMAL","response_hours":8,"resolution_hours":48 } }
```
**Reading:** the **category** is operator config — its default priority + whether it needs a field visit;
the **SLA policy** is the per-(category, priority) response/resolution matrix that stamps the ticket's
due-times. Tune SLAs by editing rows.

### `ticket_comment` / `ticket_timeline` (append-only)
```json
{ "id":"tc_1","ticket_id":"tkt_1","author_id":"agent_7","body":"Dispatching tech." }
{ "tl_1":{ "ticket_id":"tkt_1","event":"CREATED","at":"…" } }
{ "tl_2":{ "ticket_id":"tkt_1","event":"WO_LINKED","detail":"wo_2" } }
{ "tl_3":{ "ticket_id":"tkt_1","event":"RESOLVED" } }
```
**Reading:** the timeline is the immutable history (created → assigned → WO linked → resolved); comments
are the conversation. This is the audit a supervisor reads.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `TicketService` | ticket lifecycle (create/assign/comment/resolve), SLA capture, WO linkage |
| `AsrService` | advanced service request capture/validation |

## 4. API surface
`/api/tickets` (+ `/{id}/assign`, `/comments`, `/attachments`, `/work-orders`, `/resolve`),
`/api/tickets/asr`, `/api/sla-policies`. `permission:ticketing.*`; creates idempotent.

## 5. Integration (events)
- **Emits:** `TicketCreated`, `TicketResolved`, ASR events.
- **Consumes:** `WorkOrderFinalized` → resolve the linked ticket.

## 6. Processes
Service-level; ASR may start a fulfillment/WO flow.

## 7. Policy & config
`ticket_category` + `sla_policy` (operator SLA matrix), per-category routing — data.

## 8. Cross-module dependencies
- **Calls →** WorkOrder (support WO from a ticket). **Reacts to →** WorkOrder finalize (auto-resolve);
  Notification for customer updates.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| SLA-on-create | ticket SLA captured from the category policy | `TicketService::create` |
| WO-resolve | a finalized linked WO resolves its ticket | `ResolveTicketOnWorkOrderFinalized` |

## 10. Open items / deltas
- Unified interaction timeline across modules (CUST-INT-01) is a projection opportunity (additive).
