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

---

## 📖 Foundation scenarios (the patterns at work)

### 1. Tenancy isolation (pattern 1)
A WIK-scoped user `GET /api/customers` — `BelongsToOperator`'s global scope silently adds
`where operator_code='WIK'`, so MSA rows are invisible. A client can't widen this with a body field;
only `X-Operator-Code` + the `platform.cross_operator` permission changes the `Context` operator.

### 2. Outbox publish + dispatch (pattern 3)
`SubscriptionService::transitionStatus(ACTIVE)` runs **inside a DB transaction**: it updates the row
**and** inserts an `outbox_events` row for `SubscriptionActivated`. If the tx rolls back, neither
happens. A minute later `sophix:outbox:dispatch` picks up the unpublished row, fires
`OutboxEventPublished`, and stamps `published_at`. *Atomic state+event, then async fan-out.*

### 3. Inbox dedupe (pattern 3)
`ReportMetricProjector` receives the event, does `inbox_events.firstOrCreate(event_id, consumer)`; the
row's `processed_at` is null → it projects and stamps it. A **re-dispatch** of the same `event_id` finds
`processed_at` set → returns immediately. *At-most-once per consumer.*

### 4. Approval auto-approves when no policy (pattern 5)
`ApprovalService::request({entity_type:'PURCHASE_ORDER'})` with **no** matching `approval_definition` →
the request is created `AUTO_APPROVED` and emits `ApprovalAutoApproved`. *Out-of-the-box flows aren't
blocked; an operator opts into gating by adding a definition row.*

### 5. Approval gated + segregation of duties (pattern 5)
With a definition, the request is `PENDING`. The **requester** calling `decide(approve:true)` →
`SELF_APPROVAL_NOT_ALLOWED` (403) unless `allow_requester`. A **different** approver holding an
`approver_roles` role → an `approval_decision` row is written, `approvals_count` increments; when it
reaches `required_approvals` the request flips `APPROVED` and emits `ApprovalApproved`.

### 6. Idempotent retry replays (pattern 7)
`POST …/activate` with `Idempotency-Key: k1` stores `{key, request_hash, response, status}` in
`idempotency_keys`. The client times out and retries with **the same key + same body** → the middleware
returns the **stored response**, the controller never runs twice.

### 7. Idempotency conflict (pattern 7)
The same `Idempotency-Key: k1` with a **different body** → `request_hash` mismatch → **409** (a key may
not be reused for a different request).

### 8. Scope gate (pattern 7)
A region-scoped dispatcher `POST /api/work-orders {tech_region_id:'KE-NRB-KAREN'}` → `scope:TECH_REGION,
tech_region_id` calls `withinScope` → true (exact match) → allowed; `KE-MSA-NYALI` → **403 OUT_OF_SCOPE**;
a `SUPER_ADMIN` bypasses.

### 9. Rules: table else fallback (pattern 6)
`RuleEngine::evaluate('rules.tax-applicability', facts)` returns a deployed `decision_table` match;
`rules.billing.adjustment-approval` with no table deployed → the **registered code fallback** answers.
*A package always resolves deterministically.*

### 10. Workflow start → drain → correlate (pattern 4)
`OperationFramework::trigger('PAUSE')` → `WorkflowEngine.start('sub-pause')` creates `external_task`
rows; `sophix:workflow:work` drains them; a `messageCatch` parks until
`correlateMessage('sub-payment-confirmed', …)` (fired by a listener on `InvoicePaid`) resumes it.

## Foundation data model — sample rows + readings
> **Convention (applies to every doc):** each sample row shows **every domain column** (nullables
> included, as `null`). The Laravel surrogate `id` and the `created_at`/`updated_at` audit timestamps
> are omitted by convention — no reading ever depends on them.

### `outbox_events` (the transactional outbox)
Columns: `event_id, event_type, topic, aggregate_type, aggregate_id, operator_code, correlation_id, payload, headers, published_at, attempts`.
```json
{ "event_id":"evt_1","event_type":"SubscriptionActivated","topic":"subscription.lifecycle","aggregate_type":"Subscription","aggregate_id":"sub_1","operator_code":"WIK","correlation_id":"corr_88","payload":{"subscriptionId":"sub_1"},"headers":null,"published_at":"2026-06-20T10:01:00Z","attempts":1 }
{ "event_id":"evt_2","event_type":"InvoiceGenerated","topic":"billing.money","aggregate_type":"Invoice","aggregate_id":"inv_9","operator_code":"WIK","correlation_id":"corr_90","payload":{"invoiceId":"inv_9","total":"5000"},"headers":null,"published_at":null,"attempts":0 }
{ "event_id":"evt_3","event_type":"ApprovalRequested","topic":"platform.approvals","aggregate_type":"ApprovalRequest","aggregate_id":"appr_5","operator_code":"WIK","correlation_id":"corr_91","payload":{"requestId":"appr_5","entityType":"PURCHASE_ORDER","status":"PENDING"},"headers":null,"published_at":"2026-06-20T10:02:00Z","attempts":1 }
{ "event_id":"evt_4","event_type":"WorkOrderFinalized","topic":"workorder.field","aggregate_type":"WorkOrder","aggregate_id":"wo_1","operator_code":"WIK","correlation_id":"corr_92","payload":{"workOrderId":"wo_1"},"headers":null,"published_at":null,"attempts":2 }
```
**Reading:** `published_at=null` (evt_2) = **committed but not yet dispatched**; the dispatcher fans it
out, stamps `published_at`, and bumps `attempts`. evt_4 has `attempts:2` and is still unpublished (two
failed dispatch tries — it'll retry). `correlation_id` threads one business action across events;
`topic` is the routing/Kafka channel; `headers` is null when none were set.

### `inbox_events` (per-consumer dedupe)
Columns: `event_id, consumer, event_type, processed_at`.
```json
{ "event_id":"evt_1","consumer":"reporting.metrics","event_type":"SubscriptionActivated","processed_at":"2026-06-20T10:01:05Z" }
{ "event_id":"evt_1","consumer":"notification.bridge","event_type":"SubscriptionActivated","processed_at":"2026-06-20T10:01:06Z" }
{ "event_id":"evt_4","consumer":"fulfillment.resume","event_type":"WorkOrderFinalized","processed_at":"2026-06-20T10:03:00Z" }
{ "event_id":"evt_4","consumer":"workforce.capacity","event_type":"WorkOrderFinalized","processed_at":null }
```
**Reading:** dedupe is **per (event_id, consumer)** — the same event evt_1 is processed independently by
two consumers (reporting + notification). evt_4's `workforce.capacity` row has `processed_at=null`
(claimed but not yet done); a re-dispatch to a consumer whose `processed_at` is set is a no-op.

### `approval_definition` (the policy)
Columns: `definition_id, operator_code, entity_type, action, threshold_amount, approver_roles, required_approvals, allow_requester, active`.
```json
{ "definition_id":"appd_1","operator_code":"WIK","entity_type":"PURCHASE_ORDER","action":"FORCE_SYNC","threshold_amount":null,"approver_roles":["NOC_LEAD"],"required_approvals":1,"allow_requester":false,"active":true }
{ "definition_id":"appd_2","operator_code":"WIK","entity_type":"ADJUSTMENT","action":null,"threshold_amount":10000.00,"approver_roles":["BILLING_LEAD"],"required_approvals":2,"allow_requester":false,"active":true }
{ "definition_id":"appd_3","operator_code":"WIK","entity_type":"CVM_OFFER","action":"CVM_HIGH_VALUE","threshold_amount":null,"approver_roles":["RETENTION_LEAD"],"required_approvals":1,"allow_requester":true,"active":true }
{ "definition_id":"appd_4","operator_code":"WIK","entity_type":"DISCOUNT","action":null,"threshold_amount":50000.00,"approver_roles":["SALES_HEAD"],"required_approvals":1,"allow_requester":false,"active":false }
```
**Reading:** the per-operator policy. `action=null` (appd_2) matches any action of that entity_type;
`threshold_amount` (appd_2) auto-approves amounts **below** 10,000 and needs 2 approvals above it.
`allow_requester:true` (appd_3) lets the requester self-approve (waives SoD). appd_4 is `active:false`
→ ignored (discounts auto-approve until it's re-enabled).

### `approval_request` (an instance) · `status`: `PENDING|AUTO_APPROVED|APPROVED|REJECTED`
Columns: `request_id, operator_code, entity_type, action, entity_ref, amount, payload, status, approver_roles, required_approvals, approvals_count, allow_requester, requested_by, decided_by, decision_reason, decided_at`.
```json
{ "request_id":"appr_5","operator_code":"WIK","entity_type":"PURCHASE_ORDER","action":"FORCE_SYNC","entity_ref":"po_2","amount":null,"payload":{"target":"GPON"},"status":"PENDING","approver_roles":["NOC_LEAD"],"required_approvals":1,"approvals_count":0,"allow_requester":false,"requested_by":"u_buyer","decided_by":null,"decision_reason":null,"decided_at":null }
{ "request_id":"appr_6","operator_code":"WIK","entity_type":"CVM_OFFER","action":"CVM_HIGH_VALUE","entity_ref":"cvo_2","amount":null,"payload":{"discountPercent":25},"status":"AUTO_APPROVED","approver_roles":null,"required_approvals":1,"approvals_count":0,"allow_requester":false,"requested_by":"u_agent","decided_by":null,"decision_reason":null,"decided_at":"2026-06-20T09:00:00Z" }
{ "request_id":"appr_8","operator_code":"WIK","entity_type":"ADJUSTMENT","action":null,"entity_ref":"adj_2","amount":12000.00,"payload":{"direction":"DEBIT"},"status":"APPROVED","approver_roles":["BILLING_LEAD"],"required_approvals":2,"approvals_count":2,"allow_requester":false,"requested_by":"u_agent","decided_by":"u_lead2","decision_reason":"valid","decided_at":"2026-06-20T11:00:00Z" }
{ "request_id":"appr_9","operator_code":"WIK","entity_type":"ADJUSTMENT","action":null,"entity_ref":"adj_4","amount":1500.00,"payload":{},"status":"REJECTED","approver_roles":["BILLING_LEAD"],"required_approvals":1,"approvals_count":0,"allow_requester":false,"requested_by":"u_agent","decided_by":"u_lead2","decision_reason":"out of policy","decided_at":"2026-06-20T11:05:00Z" }
```
**Reading:** appr_5 is parked `PENDING` (`approvals_count:0/required:1`, waiting on a NOC_LEAD ≠
`u_buyer`). appr_6 had no policy → `AUTO_APPROVED` (decided immediately, no approver). appr_8 gathered
`approvals_count:2/2` → `APPROVED`. appr_9 was rejected (`decided_by` + `decision_reason` set, count
stays 0). `entity_ref` links back to the gated thing (the PO / adjustment / offer).

### `approval_decision` (immutable audit) · `decision`: `APPROVE|REJECT|REQUEST_REVISION|CANCEL`
Columns: `decision_id, request_id, operator_code, decision, actor_user_id, comment, decided_at`.
```json
{ "decision_id":"appdec_1","request_id":"appr_7","operator_code":"WIK","decision":"APPROVE","actor_user_id":"u_lead1","comment":null,"decided_at":"2026-06-20T10:30:00Z" }
{ "decision_id":"appdec_2","request_id":"appr_8","operator_code":"WIK","decision":"APPROVE","actor_user_id":"u_lead1","comment":"step 1 ok","decided_at":"2026-06-20T10:55:00Z" }
{ "decision_id":"appdec_3","request_id":"appr_8","operator_code":"WIK","decision":"APPROVE","actor_user_id":"u_lead2","comment":"step 2 ok","decided_at":"2026-06-20T11:00:00Z" }
{ "decision_id":"appdec_4","request_id":"appr_9","operator_code":"WIK","decision":"REJECT","actor_user_id":"u_lead2","comment":"out of policy","decided_at":"2026-06-20T11:05:00Z" }
```
**Reading:** one **immutable** row per decision. appr_8 needed 2 approvals → appdec_2 + appdec_3 from
**different** actors (SoD — the engine rejects a second decision by the same actor). The request's
`approvals_count`/`status` is derived from these rows; this audit can never be edited.

### `idempotency_keys`
Columns: `key, operator_code, request_hash, response_status, response_body`.
```json
{ "key":"act-sub_1-1","operator_code":"WIK","request_hash":"a1b2c3d4e5","response_status":202,"response_body":"{\"operation_id\":\"op_2\"}" }
{ "key":"pay-acc_1-9","operator_code":"WIK","request_hash":"9f8e7d6c5b","response_status":201,"response_body":"{\"payment_id\":\"pay_1\"}" }
{ "key":"order-c","operator_code":"WIK","request_hash":"33aa55bb77","response_status":500,"response_body":"{\"error\":\"INTERNAL\"}" }
{ "key":"in-flight-1","operator_code":"WIK","request_hash":"77bcd9ee11","response_status":null,"response_body":null }
```
**Reading:** the middleware keys on (operator, key) and stores the outcome. A retry with the same key +
matching `request_hash` **replays** the stored `response_status`/`response_body` (even a 500 — `order-c`
replays the error rather than re-running, since the business write may have committed). A **different**
`request_hash` → 409 conflict. `response_status=null` (`in-flight-1`) = the row was reserved but the
handler hasn't finished → a concurrent duplicate gets 409 "still being processed". *(There is no status
enum — the null response IS the in-flight marker.)*

## Scheduled workers (`routes/console.php`)
Outbox dispatch + workflow tick (every minute); billing cycle-close (30 min); provisioning
poll-async (5 min) / reconcile (hourly); subscription operation-timeouts (every minute); stock
reservation-expiry (10 min); dunning / pro-forma / generation-retry / wallet-expiry (daily / 15 min).
These are the heartbeat that advances async state — if "nothing happens," check the relevant worker.
