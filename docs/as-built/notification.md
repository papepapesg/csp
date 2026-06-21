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

## 2. Data model — ≥4 **complete** sample rows + readings per table
> **Completeness:** each row lists **every domain column** (nullables shown as `null`). The surrogate
> string primary key shown is the real one; `created_at`/`updated_at` are omitted by convention. These
> tables are the DD seven-table NOT-01 core + ICN-01 staff fabric (they coexist with the legacy
> `notification`/`notification_template` studio tables, not shown here).

### `notification_routing_rule` (event_type → channels + purpose)
```json
{ "id":"nrr_1","operator_code":"WIK","event_type":"InvoiceIssued","channel":"EMAIL","template_purpose_code":"INVOICE_CYCLE_POSTPAID","priority":1,"urgency":"NORMAL","category":"TRANSACTIONAL","conditions":null,"enabled":true,"needs_pdf":true }
{ "id":"nrr_2","operator_code":"WIK","event_type":"InvoiceIssued","channel":"SMS","template_purpose_code":"INVOICE_CYCLE_POSTPAID","priority":2,"urgency":"NORMAL","category":"TRANSACTIONAL","conditions":null,"enabled":true,"needs_pdf":false }
{ "id":"nrr_3","operator_code":"WIK","event_type":"DunningStageAdvanced","channel":"SMS","template_purpose_code":"DUNNING_NOTICE","priority":1,"urgency":"URGENT","category":"TRANSACTIONAL","conditions":{"stage":">=2"},"enabled":true,"needs_pdf":false }
{ "id":"nrr_4","operator_code":"WIK","event_type":"PromoBlast","channel":"SMS","template_purpose_code":"PROMO_GENERIC","priority":1,"urgency":"NORMAL","category":"MARKETING","conditions":null,"enabled":false,"needs_pdf":false }
```
**Reading:** routing is **pure config** — an (operator, event) maps to ordered (channel, template
purpose, category) rows. Adding a channel = a row, not code. `priority` orders the channels tried;
`category` drives the regulatory filter (TRANSACTIONAL ignores marketing opt-out, so nrr_4's MARKETING
row obeys opt-out); `urgency=URGENT` bypasses the customer time-window; `conditions` gates a rule on the
payload; `enabled=false` (nrr_4) lets an admin pause a rule (O-6); `needs_pdf` triggers PDF rendering.

### `notification_log` · `final_status`: `DISPATCHED|PARTIALLY_DISPATCHED|SUPPRESSED|ESCALATED|UNDELIVERABLE` & `notification_delivery_attempt` · `status`: `SENT|FAILED|PENDING_RETRY|ESCALATED`
```json
{ "id":"nl_1","operator_code":"WIK","customer_id":"cust_1","event_type":"InvoiceIssued","source_event_id":"evt_900","source_entity_id":"inv_55","channels_attempted":["EMAIL","SMS"],"final_status":"DISPATCHED","dispatched_at":"2026-06-20T09:00:02Z","manual_resend_by":null,"original_notification_id":null }
{ "id":"nl_2","operator_code":"WIK","customer_id":"cust_2","event_type":"InvoiceIssued","source_event_id":"evt_901","source_entity_id":"inv_56","channels_attempted":["EMAIL","SMS"],"final_status":"PARTIALLY_DISPATCHED","dispatched_at":"2026-06-20T09:01:00Z","manual_resend_by":null,"original_notification_id":null }
{ "id":"nl_3","operator_code":"WIK","customer_id":"cust_M","event_type":"PromoBlast","source_event_id":"evt_902","source_entity_id":null,"channels_attempted":[],"final_status":"SUPPRESSED","dispatched_at":"2026-06-20T09:02:00Z","manual_resend_by":null,"original_notification_id":null }
{ "id":"nl_4","operator_code":"WIK","customer_id":"cust_2","event_type":"InvoiceIssued","source_event_id":null,"source_entity_id":"inv_56","channels_attempted":["EMAIL"],"final_status":"DISPATCHED","dispatched_at":"2026-06-20T11:00:00Z","manual_resend_by":"agent_7","original_notification_id":"nl_2" }
```
```json
{ "id":"att_1","notification_id":"nl_1","operator_code":"WIK","channel":"EMAIL","template_id":"tpl_inv_html","recipient":"a@x.com","attempt_number":1,"status":"SENT","failure_category":null,"failure_detail":null,"channel_response":{"messageId":"m-1"},"external_reference":"m-1","dispatch_context":null,"attempted_at":"2026-06-20T09:00:02Z","next_attempt_at":null }
{ "id":"att_2","notification_id":"nl_2","operator_code":"WIK","channel":"EMAIL","template_id":"tpl_inv_html","recipient":"bounce@x.com","attempt_number":1,"status":"FAILED","failure_category":"PERMANENT_RECIPIENT","failure_detail":"hard bounce","channel_response":{"code":"550"},"external_reference":null,"dispatch_context":{"retryable":false},"attempted_at":"2026-06-20T09:01:00Z","next_attempt_at":null }
{ "id":"att_3","notification_id":"nl_2","operator_code":"WIK","channel":"SMS","template_id":"tpl_inv_sms","recipient":"+254700000002","attempt_number":1,"status":"SENT","failure_category":null,"failure_detail":null,"channel_response":{"dlr":"ok"},"external_reference":"dlr-77","dispatch_context":null,"attempted_at":"2026-06-20T09:01:01Z","next_attempt_at":null }
{ "id":"att_4","notification_id":"nl_1","operator_code":"WIK","channel":"SMS","template_id":"tpl_inv_sms","recipient":"+254700000001","attempt_number":2,"status":"PENDING_RETRY","failure_category":"TRANSIENT","failure_detail":"gateway 503","channel_response":{"code":"503"},"external_reference":null,"dispatch_context":{"retryable":true},"attempted_at":"2026-06-20T09:00:05Z","next_attempt_at":"2026-06-20T09:05:00Z" }
```
**Reading:** the log is the **audit aggregate** (one row per notification event); the attempt rows are
per-channel. nl_2 partially dispatched (att_2 EMAIL hard-bounced, att_3 SMS sent). nl_4 is a manual
resend (`manual_resend_by`/`original_notification_id` point back to nl_2). The `final_status` is
recomputed from the latest attempt per channel; `dispatch_context` carries what a retry needs.

### `template` (format-decomposed, versioned) · `status`: `ACTIVE|DRAFT|ARCHIVED|DISABLED` & `customer_notification_preference` · `email_status`: `VALID|SOFT_BOUNCED|INVALID`
```json
{ "id":"tpl_1","operator_code":"WIK","template_format":"EMAIL_SUBJECT","template_purpose_code":"DUNNING_NOTICE","locale":"en","version":1,"status":"ACTIVE","engine_type":"HANDLEBARS","template_payload":"Payment overdue","placeholder_schema":{"name":"string"},"sample_data":{"name":"Asha"},"created_by":"studio_admin" }
{ "id":"tpl_2","operator_code":"WIK","template_format":"SMS_TEXT","template_purpose_code":"DUNNING_NOTICE","locale":"en","version":1,"status":"ACTIVE","engine_type":"HANDLEBARS","template_payload":"Your bill is overdue","placeholder_schema":null,"sample_data":null,"created_by":"studio_admin" }
{ "id":"tpl_3","operator_code":"WIK","template_format":"EMAIL_HTML","template_purpose_code":"INVOICE_CYCLE_POSTPAID","locale":"sw","version":2,"status":"DRAFT","engine_type":"HTML_TO_PDF","template_payload":"<html>…</html>","placeholder_schema":{"total":"number"},"sample_data":null,"created_by":"studio_admin" }
{ "id":"tpl_4","operator_code":"WIK","template_format":"PDF","template_purpose_code":"INVOICE_CYCLE_POSTPAID","locale":"en","version":1,"status":"ARCHIVED","engine_type":"HTML_TO_PDF","template_payload":"…","placeholder_schema":null,"sample_data":null,"created_by":null }
```
```json
{ "id":"cnp_1","customer_id":"cust_M","operator_code":"WIK","email_opt_in":true,"sms_opt_in":false,"locale":"en","preferred_time_window_start":"08:00:00","preferred_time_window_end":"20:00:00","email_status":"VALID" }
{ "id":"cnp_2","customer_id":"cust_1","operator_code":"WIK","email_opt_in":true,"sms_opt_in":true,"locale":"sw","preferred_time_window_start":null,"preferred_time_window_end":null,"email_status":"SOFT_BOUNCED" }
{ "id":"cnp_3","customer_id":"cust_2","operator_code":"WIK","email_opt_in":false,"sms_opt_in":true,"locale":"en","preferred_time_window_start":null,"preferred_time_window_end":null,"email_status":"VALID" }
{ "id":"cnp_4","customer_id":"cust_3","operator_code":"WIK","email_opt_in":false,"sms_opt_in":false,"locale":"en","preferred_time_window_start":null,"preferred_time_window_end":null,"email_status":"INVALID" }
```
**Reading:** templates are **decomposed by format** (subject/HTML/text/SMS/PDF) + locale + version, so
EMAIL needs all its parts present at an `ACTIVE` version to render; `engine_type` picks the render
engine. Customer prefs gate the MARKETING channels (`email_opt_in`/`sms_opt_in` — transactional sends
regardless), pick the `locale`, bound the send to the time window, and `email_status` lets a bounced
address be skipped.

### ICN: `staff_notification` (`status`: `PROCESSING|DISPATCHED|ACKNOWLEDGED|EXPIRED`) & `staff_group_membership`
```json
{ "notification_id":"sn_1","operator_code":"WIK","source_module":"EM-CFG-04","source_task_id":"task_11","source_process_instance":"pi_1","source_business_key":"appr_77","candidate_group":"kenya-l1-kyc","template_code":"approval-needed","template_variables":{"customer":"cust_9"},"urgency":"high","fallback_mode_override":null,"deeplink_url":"/approvals/appr_77","ack_window_hours":24,"expected_recipients":4,"status":"DISPATCHED","expiry_reason":null,"acknowledged_by":null,"acknowledged_at":null,"idempotency_key":"appr_77","expires_at":"2026-06-21T09:00:00Z" }
{ "notification_id":"sn_2","operator_code":"WIK","source_module":"FUL-02","source_task_id":"task_12","source_process_instance":"pi_2","source_business_key":"kyc_55","candidate_group":"kenya-l1-kyc","template_code":"kyc-l1-approval-needed","template_variables":{"kyc":"kyc_55"},"urgency":"medium","fallback_mode_override":"SEQUENTIAL_UNTIL_ACK","deeplink_url":null,"ack_window_hours":12,"expected_recipients":3,"status":"ACKNOWLEDGED","expiry_reason":null,"acknowledged_by":"sup_01","acknowledged_at":"2026-06-20T10:05:00Z","idempotency_key":"kyc_55","expires_at":"2026-06-20T21:00:00Z" }
{ "notification_id":"sn_3","operator_code":"WIK","source_module":"BIL-04","source_task_id":null,"source_process_instance":null,"source_business_key":"refund_3","candidate_group":"finance","template_code":"refund-approval-needed","template_variables":{"amount":5000},"urgency":"high","fallback_mode_override":null,"deeplink_url":null,"ack_window_hours":null,"expected_recipients":0,"status":"EXPIRED","expiry_reason":"NO_RECIPIENTS","acknowledged_by":null,"acknowledged_at":null,"idempotency_key":"refund_3","expires_at":null }
{ "notification_id":"sn_4","operator_code":"WIK","source_module":"WO-01","source_task_id":"task_14","source_process_instance":"pi_4","source_business_key":"wo_9","candidate_group":"noc-team","template_code":"wo-stuck","template_variables":{"wo":"wo_9"},"urgency":"low","fallback_mode_override":null,"deeplink_url":null,"ack_window_hours":24,"expected_recipients":2,"status":"PROCESSING","expiry_reason":null,"acknowledged_by":null,"acknowledged_at":null,"idempotency_key":null,"expires_at":"2026-06-21T12:00:00Z" }
```
```json
{ "id":1,"operator_code":"WIK","group_code":"kenya-l1-kyc","user_id":"sup_01" }
{ "id":2,"operator_code":"WIK","group_code":"kenya-l1-kyc","user_id":"sup_02" }
{ "id":3,"operator_code":"WIK","group_code":"finance","user_id":"fin_01" }
{ "id":4,"operator_code":"WIK","group_code":"noc-team","user_id":"noc_01" }
```
**Reading:** an ICN notification fans to a **candidate group**'s members; first-ACK → `ACKNOWLEDGED`
(others suppressed — sn_2, `acknowledged_by=sup_01`); no members → `EXPIRED(NO_RECIPIENTS)` (sn_3,
`expected_recipients:0`). `idempotency_key` makes a re-dispatch a no-op; `source_business_key` threads
back to the originating EM-CFG-04/flow. The group membership is the recipient model (distinct from the
customer pipeline).

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
