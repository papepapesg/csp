# Reporting — As-Built Design (REP-01)

> **Capability codes:** REP-01 (event-sourced reporting mart) · **Module path:** `Modules/Reporting`
> **Tests:** `ReportingApi`, `ReportExportReconcile`

## 1. Purpose & boundaries
- **Owns:** the **reporting mart** — daily metrics projected from domain events — plus dashboards,
  export and a reconciliation check.
- **Does NOT own:** the source events (other modules) nor the external DWH (a connector). It is a
  **read model**, never a business-state writer.
- **Job:** turn the event stream into queryable operator-scoped daily metrics, **idempotently**.

## 📖 Scenarios (service + Foundation involvement)

### 1. An activation shows up on the dashboard
Subscription emits `SubscriptionActivated` → `sophix:outbox:dispatch` fires `OutboxEventPublished` →
`ReportMetricProjector`: inbox-dedupe, `MetricMap::for('SubscriptionActivated')` →
`[['subscriptions_activated',1]]`, increments the `report_daily_metric`. `GET /api/reports/dashboards/
operations-overview` reads it. *Proven by `ReportingApiTest`.*

### 2. Re-dispatch doesn't double-count
The same event fired again → the **inbox** (`event_id`+consumer) short-circuits → counts unchanged.
*Foundation: at-most-once projection.* *Proven by `ReportingApiTest::…idempotent_on_redispatch`.*

### 3. A termination is projected
`SubscriptionTerminated` → `subscriptions_terminated += 1`. *Proven by
`ReportingApiTest::test_subscription_termination_is_projected`.*

### 4. Payment amount aggregates
`PaymentReceived` → `[['payments_count',1],['payments_amount', amount]]` → revenue dashboard.

### 5. Reconcile — mart matches events
`GET /api/reports/reconcile` re-projects from raw events via the **same `MetricMap`** and compares to the
mart → in-sync. *Proven by `ReportExportReconcileTest`.*

### 6. Reconcile detects drift
If a mart row is manually changed, reconcile flags the delta. *Proven by `ReportExportReconcileTest`.*

### 7. CSV export to the DWH
`GET /api/reports/export/{code}` streams mart rows (the DWH boundary via `ReportExportService`).

### 8. A new metric = one MetricMap line
Add a `MetricMap` case (+ the emitting event) and the projector counts it — no schema change.

## 2. Data model — ≥4 sample rows + readings

### `report_daily_metric` (operator, date, metric_key → value)
```json
{ "operator_code":"WIK","metric_date":"2026-06-20","metric_key":"subscriptions_activated","value":12 }
{ "operator_code":"WIK","metric_date":"2026-06-20","metric_key":"subscriptions_terminated","value":2 }
{ "operator_code":"WIK","metric_date":"2026-06-20","metric_key":"payments_amount","value":248000 }
{ "operator_code":"WIK","metric_date":"2026-06-20","metric_key":"invoices_generated","value":340 }
```
**Reading:** one row per (operator, day, metric). Values are **upserted/incremented** under a lock as
events arrive. The dashboards select metric subsets; the keys come from `MetricMap` (the event→metric
contract). `inbox_events` (Foundation) guarantees each event counts once.

## 3. Services & projector
| Component | Responsibility |
| --- | --- |
| `Projectors/ReportMetricProjector` | live projection (event → metric via `MetricMap`) |
| `Support/MetricMap` | **single source** for event→metric mapping (live + reconcile) |
| `ReportExportService` / `ReportReconciliationService` | export (DWH boundary) / re-project + compare |

## 4. API surface
`/api/reports/dashboards/{code}`, `/api/reports/metrics`, `/api/reports/export/{code}`,
`/api/reports/reconcile`. `permission:report.*`.

## 5. Integration (events)
- **Consumes:** all domain events via `OutboxEventPublished` (only those in `MetricMap` count).
- **Emits:** none (read model).

## 6. Processes
Live projection on dispatch; export/reconcile on demand or scheduled.

## 7. Policy & config
`MetricMap` is the declared contract; dashboards select metric subsets.

## 8. Cross-module dependencies
- **Reacts to →** every module's events. **Connector seam →** external DWH/ETL via `ReportExportService`.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| idempotent projection | each event projected at most once per consumer | inbox dedupe |
| single mapping | live + reconcile derive identical totals | shared `MetricMap` |

## 10. Open items / deltas
- New metric = a `MetricMap` line (+ the emitting event). `subscriptions_terminated` is wired + tested.
