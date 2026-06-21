# ItOps — As-Built Design (NOC console)

> **Module path:** `Modules/ItOps` · **Tests:** `ItOpsTest`, `NocConsoleTest`

## 1. Purpose & boundaries
- **Owns:** operational observability + control — worker **heartbeats**, **service control** (RESTART/
  PAUSE/RESUME), the searchable **system log**, the NOC overview, and an end-to-end **trace**.
- **Does NOT own:** the business domains — it watches and steers the platform's own workers.
- **Job:** "is the platform healthy?" + pause/restart workers safely.

## 📖 Scenarios (service + Foundation involvement)

### 1. NOC pauses the workflow worker (persists across restarts)
`POST /api/noc/services/workflow-worker/stop` → `service_control{command:PAUSE}` + heartbeat `DOWN`. The
worker's `Heartbeat::shouldStop` returns true **and persists** the PAUSE (a restarted worker stays
down). *Proven by `ItOpsTest::test_pause_stops_the_worker_and_persists_until_resume`.*

### 2. Resume
`POST …/start` → `command:RESUME` clears the pause; the next worker loop proceeds.

### 3. One-shot restart
`POST /api/itops/services/{service}/restart` → `command:RESTART`; `shouldStop` returns true **once**,
acks + clears, so the supervisor-restarted worker runs normally. *Proven by `ItOpsTest`.*

### 4. Liveness heartbeats
Each worker calls `Heartbeat::ping('workflow-worker', …)` per loop → `service_heartbeat` `UP` with
metrics; a stale `last_seen_at` reads as unhealthy on the overview.

### 5. Search the logs
`GET /api/itops/logs?level=warning&q=NMS` → filtered `system_log` rows (DB log channel).

### 6. Trace a journey
`GET /api/noc/trace?subscriptionId=sub_1` reconstructs the timeline across outbox events, workflow
instances/tasks, provisioning commands and tickets. *Proven by `NocConsoleTest`.*

### 7. NOC overview counters
`GET /api/noc/overview` → running instances, workflow incidents, provisioning mismatches, outbox
backlog, SLA-overdue tickets — the at-a-glance health.

### 8. SLA-overdue list
`GET /api/noc/sla-overdue` surfaces past-due open tickets (reads Ticketing SLA timestamps).

## 2. Data model — ≥4 sample rows + readings

### `service_control` · `command`: `RESTART|PAUSE|RESUME|null` & `service_heartbeat` · `status`: `UP|DOWN|STARTING`
```json
{ "service":"workflow-worker","command":"PAUSE","requested_by":"u_noc","requested_at":"2026-06-20T10:00:00Z","acknowledged_at":"2026-06-20T10:00:03Z" }
{ "service":"outbox-dispatcher","command":null,"requested_by":null,"requested_at":null,"acknowledged_at":null }
{ "service":"provisioning-poller","command":"RESTART","requested_by":"u_noc","requested_at":"2026-06-20T10:02:00Z","acknowledged_at":null }
{ "service":"scheduler","command":"RESUME","requested_by":"u_noc","requested_at":"2026-06-20T10:04:00Z","acknowledged_at":"2026-06-20T10:04:01Z" }
{ "hb":{ "service":"workflow-worker","instance_id":"wf-w-1","status":"UP","metrics":{"lastBatch":7,"queueDepth":2},"last_seen_at":"2026-06-20T10:05:00Z" } }
{ "hb":{ "service":"outbox-dispatcher","instance_id":"ob-d-1","status":"DOWN","metrics":null,"last_seen_at":"2026-06-20T09:40:00Z" } }
```
**Reading:** `command=PAUSE` with an `acknowledged_at` persists (the worker re-reads it on restart and
stays down); `command=null` = run normally; `RESTART` un-acked (`acknowledged_at=null`) = will stop once
then clear. The heartbeat's `last_seen_at` + `status` (per `instance_id`) is the liveness signal the
overview reads; a stale `last_seen_at` reads unhealthy.

### `system_log`
```json
{ "id":"log_1","level":"warning","channel":"provisioning","message":"Provisioning rejected by NMS","context":{"target":"GPON"},"correlation_id":"sub_1","logged_at":"2026-06-20T10:00:00Z" }
{ "id":"log_2","level":"info","channel":null,"message":"Routine heartbeat","context":null,"correlation_id":null,"logged_at":"2026-06-20T10:05:00Z" }
{ "id":"log_3","level":"error","channel":"billing","message":"Tax signer timeout","context":{"invoice":"tax_9"},"correlation_id":"tax_9","logged_at":"2026-06-20T10:06:00Z" }
{ "id":"log_4","level":"info","channel":"billing","message":"Cycle close completed","context":{"closed":340},"correlation_id":null,"logged_at":"2026-06-20T10:10:00Z" }
```
**Reading:** structured logs on the **database** channel, filterable by `level` + free-text `q` (and
groupable by `channel`/`correlation_id`) — the NOC's searchable operational record. `correlation_id`
threads a log line into an end-to-end trace.

## 3. Services & support
| Component | Responsibility |
| --- | --- |
| `Support/Heartbeat` | `ping()` (liveness) + `shouldStop()` (honour RESTART/PAUSE/RESUME) |
| `NocController` / `ItOpsController` | overview, SLA-overdue, trace, logs, service control |

## 4. API surface
`/api/itops/{logs,services,services/{s}/restart}`, `/api/noc/{overview,sla-overdue,trace,
services/{s}/stop,services/{s}/start}`. `permission:itops.{view,manage}`.

## 5. Integration
- **Reads:** outbox/workflow/provisioning/ticket tables for the overview + trace; workers `ping`.

## 6. Processes
Workers call `Heartbeat::ping/shouldStop` in their loop; the console writes controls.

## 7. Policy & config
Service names + thresholds; logs via the `database` log channel.

## 8. Cross-module dependencies
- **Watches →** Workflow, Provisioning, Billing, Ticketing (read-only) + every worker's heartbeat.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| PAUSE persistence | a paused worker stays down across restarts until RESUME | `Heartbeat::shouldStop` |
| RESTART one-shot | a restart acks + clears, so the restarted worker runs | `Heartbeat::shouldStop` |

## 10. Open items / deltas
- PAUSE/RESUME honouring was wired during hardening (previously only RESTART worked).
