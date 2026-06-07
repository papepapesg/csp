# SOPHIX BSS — Code Tour for Mid-Level Developers

A guided, code-lens path to understanding the BSS. Each phase lists the files to
read **in order**, the concepts they teach, and a "trace it" exercise. Work it
top to bottom; later phases assume earlier ones.

> **Top known divergence from the DDs:** identity/security uses a **local**
> Laravel stack (Sanctum tokens/sessions + spatie/laravel-permission for the
> EM-CFG-03 RBAC catalog), **not** `FOUNDATION_AUTH` (Keycloak/OIDC). Swapping to
> Keycloak later = an OIDC guard behind the same `auth:sanctum` boundary + mapping
> Keycloak groups to the existing spatie roles; the rule-engine call sites and
> permission checks don't change.

## The 3 ideas that explain everything
1. **Modular monolith** — `Modules/<Bundle>` (one per DD bundle); modules talk via
   APIs/services + events, never each other's tables.
2. **Three swappable engines** replace Camunda/Drools/Kafka:
   - Workflow (`Modules/Workflow`) = Camunda — flows are DATA (`process_definition`).
   - Rules (`Modules/Rules`) = Drools — decisions are DATA (`decision_table`).
   - Outbox/Inbox (`app/Foundation/Events`) = Kafka — transactional events.
3. **High-leverage pattern** — a new operation = seed a flow + a decision table +
   one step handler. No engine code changes.

## Phase 0 — Orientation
`docs/ARCHITECTURE.md` → `docs/ENGINES.md` → `docs/IMPLEMENTATION_STATUS.md` →
`docs/RULES.md` → `composer.json` + `bootstrap/app.php`.

## Phase 1 — Foundation layer (`app/Foundation/`)
Read set, grouped by the action each group serves:
- **Request entry + ambient context:** `bootstrap/app.php`, `Http/Middleware/CorrelationId.php`,
  `Http/Middleware/ResolveOperatorContext.php`, `Support/Context.php`.
- **Identity:** `Support/Id.php`, `Models/HasPrefixedId.php`.
- **Command safety:** `Http/Middleware/EnforceIdempotency.php`, `Idempotency/IdempotencyKey.php`.
- **Responses + errors:** `Http/ApiController.php`, `Http/ApiResponse.php`,
  `Errors/DomainException.php`, `Errors/ErrorCode.php`, the `withExceptions` block in `bootstrap/app.php`.
- **Events (Kafka equivalent):** `Events/EventBus.php`, `Events/Drivers/OutboxEventBus.php`,
  `Events/DomainEvent.php`, `Events/Outbox/OutboxEvent.php`, `Events/Outbox/InboxEvent.php`,
  `Events/OutboxEventPublished.php`, `Console/DispatchOutboxCommand.php`.
- **The wiring hub:** `Providers/FoundationServiceProvider.php`.
- **Wave 3 foundation:** `Approvals/*` (EM-CFG-04), `Files/*` (FOUNDATION_FILE_STORAGE).
- **Legacy (superseded, read last):** `Workflow/*` + `Rules/NativeRuleEngine.php` — the
  original Wave-0 native operation/rules engines, later replaced by the config-driven
  `Modules/Workflow` + `Modules/Rules`. `RuleEngine` is rebound to `DataDrivenRuleEngine`.

## Phase 2 — The two engines
Rules: `Modules/Rules/app/Engine/DataDrivenRuleEngine.php` → `Models/DecisionTable.php`
→ `database/seeders/DecisionTableSeeder.php` → `Providers/RulesRuntimeProvider.php`
→ `Http/Controllers/DecisionTableController.php`.
Workflow: `Modules/Workflow/app/Contracts/{TaskHandler,TaskContext,TaskResult}.php`
→ `app/Engine/WorkflowEngine.php` → `Engine/TaskRegistry.php` →
`Models/{ProcessDefinition,ProcessInstance,ExternalTask,UserTask}.php` →
`Console/WorkflowWorkerCommand.php` → `database/seeders/ProcessDefinitionSeeder.php`.

## Phase 3 — One slice end-to-end (subscription activation)
`Modules/Subscription/routes/api.php` → `OperationController::activate` →
`Services/OperationFramework::trigger` → `WorkflowEngine::start('sub-activate')` →
handlers (`ValidateActivationHandler` [rules] → gateway → `ActivateServiceHandler`
[stub NMS] → `ActivateHandler` → `notify.send`) → `SyncOperationFromProcess` →
outbox → `ReportMetricProjector`. Proof: `tests/Feature/SubscriptionApiTest.php`.

## Phase 4 — Module tour (6-file recipe each: migrations → Models → Services → Controllers+routes → Events → tests)
Identity/catalog (`Ilm`, `Catalog`) → Subscription (the MACD set in `app/Workflow/`)
→ Money (`Billing`, `PaymentGateway`) → Field/stock (`WorkOrder`, `Workforce`, `Osr`,
`Provisioning`) → Orchestration/engagement (`Fulfillment`, `Ticketing`, `Notification`)
→ Platform (`Reporting`, `ItOps`, `Rbac`).

## Phase 5 — "Follow the data" exercises
A MACD op (upgrade) · a pure decision (dunning) · a multi-phase user-task flow
(WO shifting) · a reconciliation worker (provisioning).

## Phase 6 — Frontends & studios
`resources/js/Pages/*` (Backoffice + Workflow/Rules studios, IT-Ops) ·
`resources/mobile/App.vue` (Field PWA) · `resources/care/App.vue` (self-care PWA).

## Phase 7 — Extending it
Add a new operation with only the high-leverage pattern. Run the suite:
`scripts/dev-postgres.sh` then `php artisan test` (see `phpunit.xml`).
