# Reporting — As-Built Design (REP-01)

> **Capability codes:** REP-01 (event-sourced reporting mart) · **Module path:** `Modules/Reporting`
> **Source-of-truth tests:** `ReportingApiTest`, `ReportExportReconcileTest`

## 1. Purpose & boundaries
- **Owns:** the **reporting mart** — daily metrics projected from domain events — plus dashboards,
  export and a reconciliation check.
- **Does NOT own:** the source events (other modules) nor the external DWH (a connector). It is a
  **read model**, never a writer of business state.
- **Job:** turn the event stream into queryable operator-scoped daily metrics, idempotently.

## 📖 Scenarios — read these first

### Scenario A — a subscription activation shows up on the dashboard
1. Subscription emits `SubscriptionActivated`. `sophix:outbox:dispatch` fires `OutboxEventPublished`.
2. `ReportMetricProjector` dedupes via the **inbox** (one row per `event_id`+consumer), looks up
   `MetricMap::for('SubscriptionActivated')` → `[['subscriptions_activated', 1]]`, and increments
   that `report_daily_metric` for (operator, date).
3. `GET /api/reports/dashboards/operations-overview` reads the mart and shows the count. A re-dispatch
   of the same event **does not double-count** (inbox).
- **Proven by:** `ReportingApiTest::test_dashboard_projects_metrics_from_outbox_events` and
  `…_is_idempotent_on_redispatch`.

### Scenario B — does the mart match the events? (reconcile)
- `GET /api/reports/reconcile` re-projects from the raw events via the **same `MetricMap`** and
  compares to the mart, flagging drift.
- **Proven by:** `ReportExportReconcileTest`.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `report_daily_metric` | (operator, date, metric_key) → value | upsert/increment, locked |
| `inbox_events` (Foundation) | per-consumer dedupe | at-most-once projection |

## 3. Services & projector
| Component | Responsibility |
| --- | --- |
| `Projectors/ReportMetricProjector` | the live projection (event → metric via `MetricMap`) |
| `Support/MetricMap` | **single source of truth** for event→metric mapping (shared by projector + reconcile) |
| `ReportExportService` | CSV/feed export (the DWH boundary) |
| `ReportReconciliationService` | re-project + compare |

## 4. API surface
`/api/reports/dashboards/{code}`, `/api/reports/metrics`, `/api/reports/export/{code}`,
`/api/reports/reconcile`. Guarded by `permission:report.*`.

## 5. Integration (events)
- **Consumes:** all domain events via `OutboxEventPublished` (only those in `MetricMap` count).
- **Emits:** none (read model).

## 6. Processes
Live projection on dispatch; export/reconcile on demand or scheduled.

## 7. Policy & config
`MetricMap` is the declared event→metric contract; dashboards select metric subsets.

## 8. Cross-module dependencies
- **Reacts to →** every module's events. **Connector seam →** external DWH/ETL via `ReportExportService`.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| idempotent projection | each event projected at most once per consumer | inbox dedupe |
| single mapping | live + reconcile derive identical totals | shared `MetricMap` |

## 10. Open items / deltas
- New metric = add a `MetricMap` line (+ the emitting event). `subscriptions_terminated` is wired and
  test-covered (was a false-positive "orphan").
