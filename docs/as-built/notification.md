# Notification — As-Built Design (NOT-01 + ICN-01)

> **Capability codes:** NOT-01 (customer), ICN-01 (staff) · **Module path:** `Modules/Notification`
> **Tests:** `Not01Pipeline`, `Icn01`, `NotificationApi`, `TemplateStudio`

## 1. Purpose & boundaries
- **Owns:** two pipelines — **NOT-01** (customer: route → preference → regulatory → render → dispatch →
  audit) and **ICN-01** (staff: candidate-group fan-out) — plus the template/routing catalogs.
- **Does NOT own:** the trigger events (other modules), contact details (reads CRM), the transport
  (channel adapters).
- **Job:** turn a domain event into rendered, dispatched, audited notifications — channels from
  **config** (routing rules + prefs), never hardcoded.

## 📖 Scenarios (service + Foundation involvement)

### 1. Customer-visible account suspension → notify
ILM `CustomerAccountStatusChanged{customerVisible:true}` → `AccountStatusNotificationBridge` →
`NotificationOrchestrator::ingest`: **route** (rule → EMAIL+SMS, purpose `ACCOUNT_STATUS_CHANGE`) →
**preference** filter → **regulatory** filter → **render** templates → **dispatch** → write
`notification_log` (`DISPATCHED`). *Proven by `Not01PipelineTest`.*

### 2. An approval awaits a back-office group (ICN)
Any EM-CFG-04 `ApprovalRequested` → `NotifyApproversOnApprovalRequested` →
`StaffNotificationService::dispatch(template:'approval-needed', candidateGroup:<approver role>)` → the
group's members get EMAIL/SLACK/IN_APP_PUSH per their prefs. *Proven by `Icn01Test`.*

### 3. Dunning notice — channels from routing, not code
BIL-04 `DunningStageAdvanced` → `DunningNotificationBridge` → orchestrator routes per the operator's
`notification_routing_rule` (swap WhatsApp in by editing a row). *Proven by `Not01PipelineTest`.*

### 4. Marketing opt-out suppresses
A `PromoBlast` (category MARKETING) to a customer with `sms_opt_in:false` → preference filter drops it →
`notification_log` `SUPPRESSED` + `NotificationSuppressed`. *Proven by `Not01PipelineTest`.*

### 5. Transactional ignores opt-out
A `PtpRegistered` (TRANSACTIONAL) to the same opted-out customer → sent anyway (opt-out doesn't apply to
transactional). *Shows: category-driven regulatory rule.*

### 6. Render failure → retry queue
A missing template variable → `render_failure_queue` row; `sophix:notification:retry-render` re-renders
later. *Foundation: scheduled retry.*

### 7. Permanent bounce → fall back to the next channel
EMAIL hard-bounces → `BounceService` marks it; the orchestrator's per-channel fallback dispatches the
next channel → `notification_log` `PARTIALLY_DISPATCHED`. *Proven by `Not01PipelineTest`.*

### 8. ICN first-ack suppresses the rest
A staff notification fanned to 4 supervisors; the first to ACK → `AckService` suppresses the other
pending deliveries → notification `ACKNOWLEDGED`. *Proven by `Icn01Test`.*

### (bonus) 9. Idempotency by source event
The orchestrator processes a `source_event_id` once (Redis/cache) — a re-dispatch is a no-op.

## 2. Data model — ≥4 sample rows + readings

### `notification_routing_rule` (event_type → channels + purpose)
```json
{ "event_type":"InvoiceIssued","channel":"EMAIL","template_purpose_code":"INVOICE_CYCLE_POSTPAID","urgency":"NORMAL","category":"TRANSACTIONAL","needs_pdf":true }
{ "event_type":"InvoiceIssued","channel":"SMS","template_purpose_code":"INVOICE_CYCLE_POSTPAID","category":"TRANSACTIONAL","needs_pdf":false }
{ "event_type":"DunningStageAdvanced","channel":"SMS","template_purpose_code":"DUNNING_NOTICE","category":"TRANSACTIONAL" }
{ "event_type":"CustomerAccountStatusChanged","channel":"SMS","template_purpose_code":"ACCOUNT_STATUS_CHANGE","category":"TRANSACTIONAL" }
```
**Reading:** routing is **pure config** — an event maps to (channel, template purpose, category). Adding
a channel = a row, not code. `category` drives the regulatory filter (TRANSACTIONAL ignores marketing
opt-out). `needs_pdf` triggers PDF rendering.

### `notification_log` · `final_status`: `DISPATCHED|PARTIALLY_DISPATCHED|SUPPRESSED|ESCALATED|UNDELIVERABLE` & `notification_delivery_attempt` · `status`: `SENT|PENDING_RETRY|ESCALATED|FAILED`
```json
{ "id":"nl_1","event_type":"InvoiceIssued","customer_id":"cust_1","final_status":"DISPATCHED","channels_attempted":["EMAIL","SMS"] }
{ "id":"nl_2","event_type":"InvoiceIssued","final_status":"PARTIALLY_DISPATCHED","channels_attempted":["SMS"] }
{ "id":"nl_3","event_type":"PromoBlast","final_status":"SUPPRESSED" }
{ "att_1":{ "notification_id":"nl_2","channel":"EMAIL","status":"FAILED","failure_category":"PERMANENT_RECIPIENT" } }
```
**Reading:** the log is the **audit aggregate**; the attempt rows are per-channel. nl_2 partially
dispatched (EMAIL bounced, SMS sent). The `final_status` is recomputed from the latest attempt per
channel.

### `template` (format-decomposed) & `customer_notification_preference`
```json
{ "template_format":"EMAIL_SUBJECT","template_purpose_code":"DUNNING_NOTICE","locale":"en","status":"ACTIVE" }
{ "template_format":"SMS_TEXT","template_purpose_code":"DUNNING_NOTICE","locale":"en","status":"ACTIVE" }
{ "template_format":"EMAIL_HTML","template_purpose_code":"INVOICE_CYCLE_POSTPAID","locale":"sw","status":"DRAFT" }
{ "pref":{ "customer_id":"cust_M","sms_opt_in":false,"email_opt_in":true,"locale":"en" } }
```
**Reading:** templates are **decomposed by format** (subject/HTML/text/SMS) + locale, so EMAIL needs all
its parts present to render. Customer prefs gate channels + pick the locale.

### ICN: `staff_notification` (`status`: `PROCESSING|DISPATCHED|ACKNOWLEDGED|EXPIRED`) & `staff_group_membership`
```json
{ "notification_id":"sn_1","template_code":"approval-needed","candidate_group":"kenya-l1-kyc","status":"DISPATCHED","expected_recipients":4 }
{ "notification_id":"sn_2","template_code":"kyc-l1-approval-needed","candidate_group":"kenya-l1-kyc","status":"ACKNOWLEDGED","acknowledged_by":"sup_01" }
{ "notification_id":"sn_3","template_code":"refund-approval-needed","candidate_group":"finance","status":"EXPIRED","expiry_reason":"NO_RECIPIENTS" }
{ "member":{ "group_code":"kenya-l1-kyc","user_id":"sup_01" } }
```
**Reading:** an ICN notification fans to a **candidate group**'s members; first-ACK → `ACKNOWLEDGED`
(others suppressed); no members → `EXPIRED(NO_RECIPIENTS)`. The group membership is the recipient model
(distinct from the customer pipeline).

## 3. Services
| Service | Responsibility |
| --- | --- |
| `NotificationOrchestrator` | NOT-01 pipeline (idempotency→route→preference→regulatory→render→dispatch→audit) |
| `TemplateService` | template CRUD (Studio) |
| `RenderRetryService`/`RetryScheduler`/`BounceService` | render retry, dispatch retry/escalation, bounce |
| `Icn/StaffNotificationService` (+directory/dispatcher) | ICN-01 group fan-out + per-recipient channel plan |

## 4. API surface
`/api/notifications/preferences`, `/api/admin/notifications/{send,resend,dashboard,failure-queue,
routing/pause}`, ICN staff catalog + inbox/ack. `permission:notification.*`.

## 5. Integration (events)
- **Consumes (bridges):** `DunningNotificationBridge`, `AccountStatusNotificationBridge`,
  `NotifyApproversOnApprovalRequested`.
- **Emits:** `Notification{Dispatched,Suppressed,Escalated,Undeliverable}`, ICN `StaffNotification{…}`.

## 6. Processes
Scheduled retry/escalation/expiry workers (`RetryDispatch`, `RetryRender`, ICN `StaffRetry`/`StaffExpire`).

## 7. Policy & config
Routing rules, templates, channel config, customer & staff prefs, ICN groups + adapter bindings — all
per-operator data.

## 8. Cross-module dependencies
- **Reacts to →** Billing (dunning/tax), ILM (account status), Foundation Approvals (ICN). **Reads →**
  ILM/CRM for contacts. **Connector seam →** channel adapters.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-6 | an event with no routing rule produces no customer notice | `NotificationOrchestrator` |
| idempotency | a `source_event_id` is processed once | orchestrator cache |
| transactional override | TRANSACTIONAL ignores marketing opt-out | preference filter |

## 10. Open items / deltas
- Legacy `NotificationService::send` coexists with the orchestrator (back-compat) — not a duplicate.
- The approver-notification + account-status bridges were wired during hardening (approval templates
  were orphaned).
