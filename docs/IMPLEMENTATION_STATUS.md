# SOPHIX BSS — Implementation Status

Tracks wave-by-wave progress against the design corpus. Updated as each milestone
lands on `claude/bss-docker-implementation-oj5JD`.

Legend: ✅ done · 🚧 in progress · ⬜ not started

## Wave 0 — Platform & Controls ✅

| Item | Status | Notes |
| ---- | ------ | ----- |
| Laravel 12 + Inertia/Vue (Breeze) scaffold | ✅ | Backoffice shell with auth |
| Docker foundation (app, nginx, postgres, redis, queue, scheduler, mailpit) | ✅ | `docker-compose.yml`; Kafka/Camunda/Drools as opt-in profiles |
| PostgreSQL authoritative + Redis cache/queue/session | ✅ | `.env.example` |
| API standards (DD_API-00): correlation, operator, idempotency, pagination, error & command envelopes | ✅ | `app/Foundation/Http`, `bootstrap/app.php` |
| Prefixed-ULID identifiers | ✅ | `Foundation\Support\Id` |
| Transactional outbox/inbox event bus (FOUNDATION_KAFKA) | ✅ | swappable to Kafka |
| Native workflow/operation engine (FOUNDATION_CAMUNDA) | ✅ | `domain_operations`, `OperationManager`, `RunOperation` |
| Native rules engine (FOUNDATION_DROOLS) | ✅ | `NativeRuleEngine` |
| RBAC catalog (DD_EM-CFG-03) seed + Sanctum | ✅ | 14 roles / 27 permissions; admin user |
| Per-bundle Postman generator + environment | ✅ | `sophix:postman:generate` |
| Foundation feature tests | ✅ | `tests/Feature/Foundation/ApiStandardsTest` |
| Modular monolith wiring (`nwidart/laravel-modules`) | ✅ | `Modules/` + composer merge-plugin |
| RBAC admin module (CRUD, effective-access API) | ✅ | `Modules/Rbac`: runtime roles/permissions/matrix/assignments + effective-access; seed is just bootstrap data |
| Approval workflow catalog (EM-CFG-04) | ⬜ | |
| File storage foundation API (FOUNDATION_FILE_STORAGE) | ⬜ | |

## Wave 1 — Revenue-Critical Core ✅ (backend complete: 13/14; deeper frontends ongoing)

| Order | Capability | Key DDs | Status |
| ----- | ---------- | ------- | ------ |
| 1 | Customer/account/KYC basics | ILM-CFG-01 | ✅ `Modules/Ilm`: customer + account + KYC chain, contacts/notes/interactions, search, events, Backoffice Customers page, 5 tests, Postman bundle |
| 2 | Catalog & serviceability basics | PLM-CFG-01, SIP-01, RLM-CFG-01, ILM-CFG-02 | ✅ `Modules/Catalog`: service-class/service, package + versions + activate, tech-region, HomePass serviceability; events, 5 tests, Postman bundle |
| 3 | Subscription master | SUB-LM-01 | ✅ `Modules/Subscription`: subscription master, status state machine, events |
| 4 | Subscription workflow framework | SUB-WF-FRAMEWORK-01 | ✅ operation ledger + idempotency/concurrency + native workflow engine (activate/terminate); 5 tests, Postman bundle |
| 5 | Billing/payment basics | BIL-01, BIL-01-PAY-01, BIL-02, BIL-02-READ-01, BIL-05 | ✅ `Modules/Billing`: invoice generation + read, payment application (FIFO/targeted + surplus credit), wallet topup/debit; events, 5 tests, Postman bundle |
| 6 | First payment provider | PAY-GW-01 | ✅ `Modules/PaymentGateway`: M-Pesa/Visa/bank callback ingestion, dedupe, ILM account resolution, delegates to BIL payment application; 3 tests, Postman bundle |
| 7 | Work order & workforce basics | WO-01, EM-02 | ✅ `Modules/WorkOrder` (lifecycle + history) + `Modules/Workforce` (EM-02 contractor/team/staff registry); 5 tests, Postman bundles |
| 8 | Stock/equipment basics | OSR-01, OSR-INSTANCE-01, PLM-CFG-06 | ✅ `Modules/Osr`: SKU catalog, stock locations/movements/derived balances, serialized equipment instance registry + lifecycle ledger; events, 3 tests, Postman bundle |
| 9 | Fulfillment happy path | FUL-02 framework + WIK steps | ✅ `Modules/Fulfillment`: order capture orchestration (capture→validate→payment→subscription→install→activation) across SUB/WO modules; 3 E2E tests, Postman bundle |
| 10 | Service activation | SUB-WF-ACTIVATE-01, FUL-03 | ✅ FUL-03 completion triggers SUB-WF activation end-to-end (order complete -> subscription ACTIVE) |
| 11 | Notifications/internal tasks | NOT-01, ICN-01 | ✅ `Modules/Notification`: multi-channel notifications (queue→send) + internal messages; provider stub, events, 3 tests, Postman bundle |
| 12 | Basic ticketing | TCK-01 | ✅ `Modules/Ticketing`: case lifecycle (open→assign→comment→resolve→close), SLA by priority, timeline, ticket→WO linkage (ASR as categories); 3 tests, Postman bundle |
| 13 | Essential frontends | FE-APP-00/01/02/03 | ✅ FE-APP-01 Backoffice (Customers, Tickets, Reports, Workflow Studio, IT-Ops) + FE-APP-02/03 SOPHIX Field PWA (offline-capable) |
| 14 | Minimal reporting | REP-01, FE-APP-05 | ✅ `Modules/Reporting`: event-sourced reporting mart (outbox→inbox projector), operations + revenue dashboards, metric time-series API; 4 tests, Postman bundle |


## Architecture corrections (post-Wave-1 review)

| Item | Status | Notes |
| ---- | ------ | ----- |
| Config-driven workflow engine (flows as DATA, not code) | ✅ | `Modules/Workflow`: process_definition graphs, external-task workers, topic→handler toolbox; operator override with zero code (proven by test) |
| PostgreSQL everywhere incl. tests (drop SQLite) | ✅ | caught a real concurrency bug SQLite masked |
| Workflow Studio (Vue Flow drag-and-drop) | ✅ | `/workflow/studio` — author/deploy flows from the toolbox |
| IT-Ops console (process trace, worker/incident monitor) | ✅ | `/workflow/ops` |
| IT-Ops log search + service control | ✅ | `Modules/ItOps` + `/itops`: searchable DB logs, worker heartbeats up/down + queue depths, graceful restart control |
| NMS/provisioning stub adapter (PROV-INT-01) | ✅ | `Modules/Provisioning`: command ledger + swappable adapter; activation flow drives the NMS end-to-end, failure gates activation; **desired/observed reconciliation + polling worker + NOC force-sync** (Wave 2) |
| Tax gateway stub adapter (BIL-02-TAX-01) | ✅ | TaxGateway interface + StubTaxGateway (KRA-style fiscalisation); issue tax-invoice endpoint, swappable via SOPHIX_TAX_DRIVER |
| De-hardcode catalogs → data | ✅ | rules + RBAC + SLA now runtime catalogs (operator-overridable). WO/OSR status transitions are deliberate guard-validation in code (per MVP baseline: Drools only for configurable policy, not fixed validation) |
| Data-driven rules (decision tables, Drools-equivalent) | ✅ | `Modules/Rules` engine + **Rules Studio UI** (`/rules/studio`) + WIRED into the live activation gateway (ValidateActivationHandler evaluates `activation.eligibility`); operator override proven by test |
| PWA mobile frontends (sales, contractor) | ✅ | `SOPHIX Field` installable PWA at `/m`: token auth, contractor job execution (claim/start/finalize) + sales quick-capture, offline action queue + service-worker shell |

## Wave 2 — Operational Hardening ⬜

**Done:**
- terminate, **pause, resume** (SUB-WF-PAUSE/RESUME-01 — data-defined flows
  `sub-pause`/`sub-resume` + `rules.subscription.pause`/`.resume`, generic
  `sub.validate-operation` step).
- **dunning & non-payment suspension** (BIL-04 + SUB-WF-SUSPEND-NP) — `DunningService`
  scans overdue debt, drives a configurable escalation policy
  (`rules.billing.dunning`: warn→restrict→suspend→terminate) via a per-account level
  and the owning SUB-WF operations; `sub-suspend` flow + `rules.subscription.suspend-np`;
  daily `sophix:billing:dunning-run`; payment settlement de-escalates.
- **subscription restriction** (SUB-WF-RESTRICT-01) — the one operation that does NOT
  mutate status_code: ADD/REMOVE mutate `active_restrictions[]` only, via the
  `sub-restrict` flow (intent as a process variable) + `sub.put-active-restrictions`
  step. SUB-LM restriction catalog + per-operator `subscription_restrict_config`;
  synchronous DD rejections (ALREADY_RESTRICTED / UNKNOWN_RESTRICTION_CODE /
  INVALID_STATE_FOR_RESTRICTION / SYSTEM_MANAGED_RESTRICTION) + dunning-marker
  protection (R-DM-2/3/4); non-exclusive per R-FW-1. Sub-resource REST
  (POST/DELETE/GET `/restrictions`). Dunning L2 drives a DUNNING_DRIVEN restriction;
  resume-after-payment lifts dunning-marked restrictions.

- **WO support flow** (WO-01-FLOW-SUPPORT) — config-driven `wo-support` engine flow:
  warranty-linkage (RPT spawn) → site-visit-decision (`rules.workorder.site-visit-decision`
  reads the job-type catalog) → await-resolution user task → resolution-gate
  (`rules.workorder.resolution-gate`) → RESOLVED (capture bindings) /
  NOT_RESOLVED_ESCALATE (Pattern A: finalize + spawn QCS WO for NOC, linked by
  master_wo_id) / AREA_OUTAGE → finalize + warranty window + WorkOrderSupportCompleted.
  `wo_job_type_catalog` + `wo_flow_config` per operator; REST start-flow + resolve.

- **provisioning reconciliation** (PROV-INT-01 §7.3) — desired/observed state model:
  a confirmed command snapshots `provisioning_desired_state`; the
  `sophix:provisioning:reconcile` polling worker (hourly) fetches observed state per
  target via the swappable adapter, records `provisioning_observed_state`, and opens
  `provisioning_reconciliation_item` mismatches (no auto-fix, R-PROV-08). NOC API:
  runs/items list, manual run, permission-gated + audited force-sync (R-PROV-07/09).
  Stub adapter simulates clean/drift/not-present for end-to-end testing.

- **subscription upgrade / downgrade** (SUB-WF-UPGRADE-01 / DOWNGRADE-01) —
  config-driven `sub-upgrade` / `sub-downgrade` flows: validate target package
  (`rules.subscription.upgrade` / `.downgrade`: currency match, target ACTIVE, price
  delta direction) → gateway → commit package change (pins previous_package_ref,
  sets new package_ref/version + transition type, stays ACTIVE) → notify.
  `subscription_upgrade_config` per operator+kind; REST `/upgrade` + `/downgrade`.

- **subscription relocation / migration** (SUB-WF-RELOCATION-01 / MIGRATION-01) —
  `sub-relocation` / `sub-migration` flows: validate target HomePass
  (`rules.subscription.relocation` / `.migration`: target SERVICEABLE, differs from
  source; migration requires a technology change) → commit HomePass change (pins
  previous_homepass_id; migration also changes the package) → notify. REST
  `/relocate` + `/migrate`. The full SUB-WF MACD set is now config-driven.

- **equipment swap & RMA** (OSR-RMA-01) — config-driven `osr-swap` flow: validate
  eligibility (`rules.osr.swap.eligibility`: billing/source-state gating, no truck
  roll on failure) → reserve slot → create WO → field-visit user task → recover
  source → provision OSS (stub) → complete. The signature value: recovered units
  route back to the **recovering contractor's** warehouse, not main
  (`rules.osr.recovered-routing`), closing the documented ~40-unit stale-equipment
  gap — proven by test (writes the OSR-INSTANCE lifecycle + OSR-01 stock movement to
  the contractor van). `equipment_swap_request` root + `vendor_rma_stub`; new
  instance states (IN_FIELD_DEFECTIVE/RECOVERED_BY_CONTRACTOR/RESERVED_FOR_WO).
  REST `/swap-requests/{kind}` + field-visit. (EQP/EQU pickup/upgrade flows pending.)

**Pending:** WO shifting · OSR-RMA EQP/EQU ·
customer self-care · reporting exports/reconciliation.

## Wave 3 — Advanced Commercial, Assurance & Audit ⬜

upgrade/downgrade/relocation/migration · FUL-09/10 · campaigns/discounts/CVM ·
ASR specialised flows · procurement & inventory audit · field audits · USSD ·
offline mediation/rating · advanced franchise/sales attribution.
