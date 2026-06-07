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
| RBAC admin module (CRUD, scopes, effective-access API) | ⬜ | seed only for now; full module in a later pass |
| Approval workflow catalog (EM-CFG-04) | ⬜ | |
| File storage foundation API (FOUNDATION_FILE_STORAGE) | ⬜ | |

## Wave 1 — Revenue-Critical Core ⬜

| Order | Capability | Key DDs | Status |
| ----- | ---------- | ------- | ------ |
| 1 | Customer/account/KYC basics | ILM-CFG-01 | ✅ `Modules/Ilm`: customer + account + KYC chain, contacts/notes/interactions, search, events, Backoffice Customers page, 5 tests, Postman bundle |
| 2 | Catalog & serviceability basics | PLM-CFG-01, SIP-01, RLM-CFG-01, ILM-CFG-02 | ✅ `Modules/Catalog`: service-class/service, package + versions + activate, tech-region, HomePass serviceability; events, 5 tests, Postman bundle |
| 3 | Subscription master | SUB-LM-01 | ⬜ |
| 4 | Subscription workflow framework | SUB-WF-FRAMEWORK-01 (+WORKERS) | ⬜ |
| 5 | Billing/payment basics | BIL-01, BIL-01-PAY-01, BIL-02, BIL-02-READ-01, BIL-05 | ⬜ |
| 6 | First payment provider | PAY-GW-01 | ⬜ |
| 7 | Work order & workforce basics | WO-01(+FRAMEWORK, FLOW-INSTALLATION), EM-02 | ⬜ |
| 8 | Stock/equipment basics | OSR-01, OSR-INSTANCE-01, PLM-CFG-06 | ⬜ |
| 9 | Fulfillment happy path | FUL-02 framework + WIK steps | ⬜ |
| 10 | Service activation | SUB-WF-ACTIVATE-01, FUL-03 | ⬜ |
| 11 | Notifications/internal tasks | NOT-01, ICN-01 | ⬜ |
| 12 | Basic ticketing | TCK-01 | ⬜ |
| 13 | Essential frontends | FE-APP-00/01/02/03 | ⬜ |
| 14 | Minimal reporting | REP-01, FE-APP-05 | ⬜ |

## Wave 2 — Operational Hardening ⬜

pause/resume/terminate · dunning & non-payment suspension · WO support/shifting ·
OSR-RMA/swap · provisioning reconciliation · customer self-care ·
reporting exports/reconciliation.

## Wave 3 — Advanced Commercial, Assurance & Audit ⬜

upgrade/downgrade/relocation/migration · FUL-09/10 · campaigns/discounts/CVM ·
ASR specialised flows · procurement & inventory audit · field audits · USSD ·
offline mediation/rating · advanced franchise/sales attribution.
