# The Spine — Foundation patterns every module reuses

Read this once. The platform is a **Laravel 11 modular monolith** (`nwidart/laravel-modules`):
17 domain modules under `Modules/*`, each with its own providers, `routes/api.php`,
`database/migrations`, `Models`, `Services`, `Events`, `Listeners`, `Workflow` handlers and
`tests`. Everything cross-cutting lives in **`app/Foundation`**. Master these ~7 patterns and
every module reads as "business logic on top of the spine."

---

## 🎬 One worked example — "Jane activates her subscription"
Follow this once; it touches **every** pattern below. (Proven by `Subscription/tests/Feature/SubscriptionApiTest::test_activate_runs_workflow_and_sets_active`.)

1. **Request** (a back-office agent clicks "Activate"):
   ```http
   POST /api/subscriptions/sub_123/activate
   Authorization: Bearer <sanctum token>
   Idempotency-Key: act-sub_123-1
   ```
2. **Middleware chain** (pattern 1,2,7): `auth:sanctum` → `permission:subscription.activate` →
   (`scope` if declared) → `idempotency` (no prior key → proceeds) → `ResolveOperatorContext` pinned
   `Context::operatorCode()` = `WIK`.
3. **Service** (`OperationController` → `OperationFramework::trigger('ACTIVATE')`): checks idempotency
   + single-in-flight, then **in one DB transaction** writes a `subscription_operation` row
   (`current_state=INITIATED`) and **publishes** `SubscriptionOperationStarted` to the **outbox**
   (pattern 3 — the row and the event commit together or not at all).
4. **Starts the workflow** (pattern 4): `WorkflowEngine::start('sub-activate', businessKey=sub_123,
   {subscriptionId, operationId, …})` creates a `ProcessInstance` + `ExternalTask` rows. HTTP returns
   `202` with the operation id. **Nothing else has happened synchronously.**
5. **Outbox dispatch** (every minute, or `artisan sophix:outbox:dispatch` in tests): fires
   `OutboxEventPublished` → `Reporting` projector counts it, `Notification` may notify. (Inbox dedupe
   means a re-run won't double-count.)
6. **Workflow worker** (`sophix:workflow:work`) drains the tasks, one handler per topic:
   `ValidateActivationHandler` → `BillingIntentHandler` (if there's an activation fee and it's
   pay-first, this **parks** the flow on a `ful-payment-received` catch until `InvoicePaid` arrives —
   pattern 3 again) → `ActivateHandler` calls `SubscriptionService::transitionStatus(ACTIVE)` which
   emits `SubscriptionActivated` → `FulfillmentCallHandler` calls `ProvisioningService::broadcast()`.
7. **Reconcile:** when the instance ends, `ProcessInstanceEnded` → `SyncOperationFromProcess` closes
   the `subscription_operation` ledger (`final_state`, emits `SubscriptionOperationCompleted`).
8. **Downstream of `SubscriptionActivated`:** Billing anchors the first cycle, Reporting increments
   `subscriptions_activated`, Notification sends the welcome notice — all as **reactions**, none
   called synchronously from step 3.

> The shape never changes: **thin controller → service (mutate + emit in one tx) → async dispatch →
> listeners/workflow react.** Learn it here; every module repeats it.

---

## 1. Tenancy & Context — *who and which operator*
- `App\Foundation\Support\Context` holds the per-request **operator code** and **correlation id**.
  Resolved server-side by `ResolveOperatorContext` middleware; the `X-Operator-Code` header only
  widens scope for a principal with `platform.cross_operator`.
- `App\Foundation\Tenancy\BelongsToOperator` (model trait) adds a **global query scope** filtering
  every read by `operator_code`, and pins `operator_code` on create from `Context` — so tenant
  isolation is automatic and can't be bypassed by a client-supplied value.
- **Takeaway:** you almost never filter by operator by hand; the trait does it.

## 2. HTTP & errors — *thin controllers, one error model*
- Controllers validate + delegate to a service; responses go through `App\Foundation\Http\ApiResponse`
  (`item` / `created` / `paginated` / `error`).
- Business failures throw `App\Foundation\Errors\DomainException` (carries `errorCode`, `status`,
  `nextAction`, `fieldErrors`). `bootstrap/app.php` renders the **SOPHIX standard error model** for
  all `api/*` requests (DD_API-00 §8).
- Route guards stack: `auth:sanctum` → `permission:<code>` → `scope:<type>,<key>` (optional) →
  `idempotency` (optional) → controller.

## 3. Events — *the integration backbone (transactional outbox)*
- `App\Foundation\Events\EventBus` is the interface; the default `Drivers\OutboxEventBus::publish()`
  **only writes an `outbox_events` row, inside the same DB transaction** as the state change.
- A scheduler command **`sophix:outbox:dispatch`** (`DispatchOutboxCommand`, every minute) forwards
  committed rows by firing **`OutboxEventPublished`** in-process (native driver) or to Kafka.
- Module **listeners** subscribe to `OutboxEventPublished`, switch on `$published->event->event_type`,
  and react. Consumers dedupe through an **inbox** (`InboxEvent`, one row per `event_id`+consumer)
  so replays never double-process (e.g. `Reporting\Projectors\ReportMetricProjector`).
- **Pattern:** publish a `DomainEvent(type, topic, payload, aggregateType, aggregateId)` from inside
  your service's transaction; never call another module synchronously for a side-effect that can be
  a reaction. In tests, drive it with `$this->artisan('sophix:outbox:dispatch')`.

## 4. Workflow — *config-defined processes, external-task workers*
- Long/multi-step processes are **data**: a `process_definition` graph (authored in the Studio),
  **not** code. `Modules\Workflow\Engine\WorkflowEngine::start(processKey, businessKey, variables,
  operator)` creates a `ProcessInstance` and `ExternalTask` rows per service-task node.
- Each step is a **`TaskHandler`** (interface: `topic()`, `label()`, `handle(TaskContext): TaskResult`)
  registered into the **`TaskRegistry`** by a module's `*WorkflowProvider`.
- A shared worker **`sophix:workflow:work`** (`--once` = drain) fetch-and-locks `CREATED` tasks by
  topic, runs the handler, and completes/fails them. `sophix:workflow:tick` fires due timers and
  reaps dead locks.
- Message catches resume via `WorkflowEngine::correlateMessage(name, businessKey, vars)` — used to
  wake a parked flow on an event (e.g. `WorkOrderFinalized`, `CustomerKycApproved`).
- **Mantra:** *new flow = compose registered topics (config); new step = register one handler (code).*

## 5. Approvals — *EM-CFG-04, config-driven maker-checker*
- `App\Foundation\Approvals\ApprovalService::request($data)` matches an **`approval_definition`**
  (operator + entity_type + optional action). No matching policy ⇒ **auto-approved**; a policy ⇒
  **PENDING** (with `approver_roles`, `required_approvals`, `threshold_amount`).
- `decide($request, $approve, $actorUser)` enforces **segregation of duties** (requester can't
  self-approve unless `allow_requester`), approver-role membership, and writes an immutable
  `approval_decision` row. On final approval it emits **`ApprovalApproved`** (topic
  `platform.approvals`).
- Owning modules resume on the outcome via a listener (e.g. `Ilm\Listeners\ResumeCvmOfferOnApproval`,
  `Osr` PO decide, `Provisioning` force-sync). The notification side is bridged to ICN-01
  (`NotifyApproversOnApprovalRequested`).
- **Pattern:** gate a sensitive action with `request()`, store the `request_id`, resume on
  `ApprovalApproved`/`ApprovalRejected`.

## 6. Rules — *operator-overridable decision tables*
- `App\Foundation\Rules\RuleEngine::evaluate('rules.<package>', $facts)` returns a decision. The
  package is a `decision_table` (data) when deployed, else a **registered code fallback** (e.g.
  `rules.billing.adjustment-approval`, `rules.tax-applicability`, `rules.cvm.offer`).
- **Pattern:** branch on policy through a rules package, never a hardcoded `if` for operator-varying
  behaviour.

## 7. Idempotency & scope — *safe retries, data scope*
- `idempotency` middleware (`EnforceIdempotency`, opt-in per route): with an `Idempotency-Key`, same
  key+body replays the stored response; same key+different body ⇒ `409`.
- `scope:<type>,<key>` middleware (`Rbac\Http\Middleware\EnforceScope`): after `permission`, checks
  the target value (route/body) is within the caller's RBAC scope (GLOBAL/OPERATOR/SUPER_ADMIN bypass).

---

## The life of … (trace these once)

**A write request:** `HTTP → auth:sanctum → permission → scope? → idempotency? → Controller (validate)
→ Service (DB tx: mutate + publish DomainEvent to outbox) → ApiResponse`. Then async:
`sophix:outbox:dispatch → OutboxEventPublished → module listeners react`.

**A workflow operation:** `Service.trigger() → WorkflowEngine.start(process_definition) → ExternalTask
rows → sophix:workflow:work drains → TaskHandler.handle → complete → next node → … →
ProcessInstanceEnded → operation ledger reconciled`.

**An approval:** `Service → ApprovalService.request() → (PENDING) → decide endpoint →
ApprovalApproved (outbox) → owning-module resume listener applies the outcome`.

## Scheduled workers (`routes/console.php`)
Outbox dispatch + workflow tick (every minute); billing cycle-close (30 min); provisioning
poll-async (5 min) / reconcile (hourly); subscription operation-timeouts (every minute); stock
reservation-expiry (10 min); dunning / pro-forma / generation-retry / wallet-expiry (daily / 15 min).
These are the heartbeat that advances async state — if "nothing happens," check the relevant worker.
