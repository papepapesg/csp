# ItOps — As-Built Design (NOC console)

> **Module path:** `Modules/ItOps` · **Source-of-truth tests:** `ItOpsTest`, `NocConsoleTest`

## 1. Purpose & boundaries
- **Owns:** operational observability + control — worker **heartbeats**, **service control**
  (RESTART / PAUSE / RESUME), the searchable **system log**, the NOC overview, and an end-to-end
  **trace** that stitches a journey across modules.
- **Does NOT own:** the business domains — it watches and steers the platform's own workers.
- **Job:** let operators see "is the platform healthy?" and pause/restart workers safely.

## 📖 Scenarios — read these first

### Scenario A — NOC pauses the workflow worker
1. **Request:** `POST /api/noc/services/workflow-worker/stop` → writes a `service_control` row
   (`command=PAUSE`) + marks the heartbeat `DOWN`.
2. The worker calls `Heartbeat::shouldStop('workflow-worker')` each loop: a `PAUSE` returns `true`
   **and persists** (so a supervisor-restarted worker stays down) — unlike `RESTART`, which is a
   one-shot ack-and-clear.
3. `POST …/start` writes `command=RESUME`, which clears the pause; the next worker loop proceeds.
- **Proven by:** `ItOpsTest::test_pause_stops_the_worker_and_persists_until_resume`.

### Scenario B — trace a customer journey
- `GET /api/noc/trace?subscriptionId=sub_1` reconstructs the timeline across outbox events, workflow
  instances/tasks, provisioning commands and tickets — one chronological view.
- **Proven by:** `NocConsoleTest::test_end_to_end_trace_reconstructs_a_subscription_journey`.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `service_heartbeat` | last-seen + metrics per worker | best-effort |
| `service_control` | pending command (RESTART/PAUSE/RESUME) | PAUSE persists; RESTART one-shot |
| `system_log` | searchable structured logs (DB channel) | level/q filterable |

## 3. Services & support
| Component | Responsibility |
| --- | --- |
| `Support/Heartbeat` | `ping()` (liveness) + `shouldStop()` (honour RESTART/PAUSE/RESUME) |
| `NocController` / `ItOpsController` | overview, SLA-overdue, trace, logs, service control |

## 4. API surface
`/api/itops/{logs,services,services/{s}/restart}`, `/api/noc/{overview,sla-overdue,trace,
services/{s}/stop,services/{s}/start}`. Guarded by `permission:itops.{view,manage}`.

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
