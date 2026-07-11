# SOPHIX BSS — Architecture & Design-Doc Mapping

This document explains how the SOPHIX V3 design corpus (`docs/design`, assuming a
Java/Keycloak/Camunda/Drools/Kafka reference stack) is realised as an **all-Laravel**
implementation, per the binding **MVP Runtime Simplification Baseline**.

## 1. Guiding rules (from the design corpus)

These are enforced across the codebase (HLD §2.1, MVP baseline §1):

1. One **write owner** per business object. Other modules call APIs or consume events — never write another module's tables.
2. PostgreSQL is **authoritative**; Redis is **non-authoritative acceleration** only.
3. Events are **facts after commit** — published via a transactional **outbox/inbox**, idempotent consumers.
4. **Camunda** (workflow) only for long-running / async / human-approved / retryable / compensating work.
5. **Drools** (rules) only for configurable business **policy**, side-effect free — not fixed validation.
6. Dense screens use **panelized reads** with independent failure; dashboards use **REP-01**, not operational modules.
7. Revenue-critical journeys first (Wave 1) before advanced commercial/assurance/audit (Waves 2–3).

## 2. Reference stack → Laravel mapping

| Design-doc component        | Laravel-native implementation                                              | Swap-in path |
| --------------------------- | -------------------------------------------------------------------------- | ------------ |
| Keycloak (OIDC/JWT)         | Sanctum (SPA session + API tokens) + `spatie/laravel-permission` RBAC      | OIDC adapter on the `web` guard |
| Camunda (orchestration)     | **Config-driven workflow engine** (`Modules/Workflow`): process_definition graphs (data, React-Flow authored) + external-task workers + topic→handler toolbox. Flows are config, NOT code — operator override = a definition row | Real Camunda via external-task REST (same worker pattern) |
| Drools (decisions)          | `RuleEngine` contract + `NativeRuleEngine` (registered side-effect-free rule sets) | `SOPHIX_RULES_DRIVER=drools` + KIE server client |
| Kafka (events)              | `EventBus` → `OutboxEventBus` (outbox table) + `DispatchOutboxCommand` → in-process `OutboxEventPublished` | `SOPHIX_EVENT_BUS=kafka` + `KafkaEventBus::produce()` |
| PostgreSQL                  | PostgreSQL 16 (`pgsql` connection)                                          | — |
| Redis                       | Redis 7 (cache, queue, session)                                            | — |
| API Gateway                 | nginx + foundation middleware (correlation, operator, idempotency, rate limit) | dedicated gateway later |
| Microservice deploy groups  | Modular monolith; "DD = capability contract, not deployment boundary" (baseline §1.1) | extract a module to its own service when throughput/isolation demands |

Drivers are selected in [`config/sophix.php`](../config/sophix.php) and `.env`.

## 3. Foundation (Wave 0) — `app/Foundation`

| Area | Key classes |
| ---- | ----------- |
| Identifiers | `Support\Id` (prefixed ULIDs: `sub_…`, `op_…`, `cust_…`) |
| Request context | `Support\Context` (correlation id, operator), `Http\Middleware\CorrelationId`, `ResolveOperatorContext` |
| Idempotency | `Http\Middleware\EnforceIdempotency`, `Idempotency\IdempotencyKey` |
| Responses | `Http\ApiResponse` (item/paginated/accepted/created/error), `Http\ApiController` |
| Errors | `Errors\ErrorCode`, `Errors\DomainException` + global renderer in `bootstrap/app.php` |
| Events | `Events\EventBus`, `DomainEvent`, `Drivers\OutboxEventBus`/`KafkaEventBus`, `Outbox\OutboxEvent`/`InboxEvent`, `OutboxEventPublished`, `Console\DispatchOutboxCommand` |
| Workflow | `Workflow\Operation`, `Workflow`, `OperationManager`, `RunOperation` |
| Rules | `Rules\RuleEngine`, `Rules\NativeRuleEngine` |
| RBAC | `database/seeders/RbacSeeder` (DD_EM-CFG-03) |
| Tooling | `Console\GeneratePostmanCommand` (per-bundle Postman) |

## 4. Capability-module layout

SOPHIX is a capability-modular monolith. A physical module is a business or
platform capability boundary, not necessarily a deployment boundary. Large
families are deliberately decomposed (for example Billing core, Dunning, Tax,
Wallet, Mediation, Intent and Adjustments).

Every completed module owns its:

- models and migrations;
- typed catalogs and effective-dated configuration;
- operational state and append-only ledgers/history;
- REST API and permissions;
- application services, events, workflow handlers and rule calls;
- operational commands and scheduled maintenance;
- unit/feature tests and runbook documentation.

Cross-module callers depend on Foundation contracts or published events. They
must not update another module's Eloquent models. Workflow is consumed through
`WorkflowRuntime`; similar ports are used as other boundaries are hardened.

Current capability families:

| Family | Physical capabilities |
| --- | --- |
| Customer | `Ilm`, `IlmCvm` |
| Commercial catalog | `Catalog`, `CatalogNetwork`, `CatalogRating`, `CatalogDiscount`, `CatalogTax` |
| Subscription/order | `Subscription`, `Fulfillment` |
| Billing | `Billing`, `BillingIntent`, `BillingMediation`, `BillingWallet`, `BillingDunning`, `BillingAdjustments`, `BillingTax`, `PaymentGateway` |
| Field/inventory | `WorkOrder`, `WorkOrderFieldAudit`, `Workforce`, `Osr`, `OsrProcurement`, `OsrSwap` |
| Engagement | `Notification`, `NotificationIcn`, `Ticketing` |
| Integration/platform | `Provisioning`, `Workflow`, `Rules`, `Rbac`, `ItOps`, `Reporting` |

### 4.1 Data ownership vocabulary

Each module classifies data explicitly:

| Kind | Meaning |
| --- | --- |
| System semantic | A code understood by executable control flow; adding one requires code |
| Catalog | Module-owned business choices/reference data |
| Configuration/policy | Selects how an implemented behavior executes |
| Operational state | Current state of an aggregate such as a subscription or work order |
| Ledger/history | Immutable facts such as payments, stock movements and state transitions |

Catalog entries that are designed but not executable in the current release use
`REFERENCE_ONLY` or `VALIDATION_ONLY`; only `EXECUTABLE` entries may enter
control flow. `DEPRECATED` preserves historical references while preventing new
use. Executable catalog entries may select a registered, system-owned
`behavior_key`; arbitrary catalog codes are never used as hidden branching logic.

### 4.2 Operational ownership

Every physical module provides at least a read-only `ops-status` or equivalent
check. Relevant modules also provide `show`, `validate`, `explain`, `reconcile`,
`retry`, `sweep` and controlled repair commands. Module providers register their
own schedules; `routes/console.php` contains platform-wide schedules only.

See `docs/OPERATIONS.md` for command conventions and safety requirements.

### 4.3 Migration ownership

Every physical module owns a `database/migrations` history, including capabilities
split from an older parent module. Migration files retain their original timestamped
basename when ownership moves, so existing deployments keep the same Laravel migration
ledger identity and do not execute the migration again. Basenames remain globally unique.
`ModuleMigrationOwnershipTest` enforces both module coverage and global uniqueness.

## 5. Build order

Per `Sophix_V3_DD_Reading_And_Implementation_Order` and the MVP baseline:

- **Wave 0 — Platform & controls** (this foundation): auth/RBAC, API standards, outbox/inbox, workflow & rules engines, Docker stack. ✅
- **Wave 1 — Revenue-critical core:** customer → catalog → subscription → billing/payment → fulfillment happy path → work order install → OSR basics → activation → notifications → ticketing → minimal reporting.
- **Wave 2 — Operational hardening:** pause/resume/terminate, dunning & non-payment suspension, WO support/shifting, OSR-RMA/swap, provisioning reconciliation, self-care, reporting exports.
- **Wave 3 — Advanced commercial/assurance/audit:** upgrade/downgrade/relocation/migration, campaigns/discounts/CVM, ASR specialised flows, procurement & inventory audit, field audits, USSD, offline mediation/rating.
