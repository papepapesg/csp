# Notification — As-Built Design (NOT-01 + ICN-01)

> **Capability codes:** NOT-01 (customer notifications), ICN-01 (internal/staff comms) · **Module
> path:** `Modules/Notification` · **Source-of-truth tests:** `Not01PipelineTest`, `Icn01Test`,
> `NotificationApiTest`, `TemplateStudioTest`

## 1. Purpose & boundaries
- **Owns:** two delivery pipelines — **NOT-01** (customer-facing: routing rules → preferences →
  regulatory filter → render → multi-channel dispatch → audit) and **ICN-01** (staff: candidate-group
  fan-out to back-office users) — plus the template/routing catalogs.
- **Does NOT own:** the events that trigger notices (other modules), the contact details (reads from
  CRM), the channel transport (adapters). It decides **what to send, to whom, on which channel**.
- **Job:** turn a domain event into rendered, dispatched, audited notifications — channels chosen by
  **config (routing rules + customer/staff prefs)**, never hardcoded.

## 📖 Scenarios — read these first

### Scenario A — a customer-visible account suspension notifies the customer
1. ILM emits `CustomerAccountStatusChanged{customerVisible:true, status:INACTIVE}`.
2. `AccountStatusNotificationBridge` resolves the account's customer + contacts and calls
   `NotificationOrchestrator::ingest('CustomerAccountStatusChanged', 'WIK', payload, {contacts})`.
3. **Pipeline:** route (rules say EMAIL+SMS, purpose `ACCOUNT_STATUS_CHANGE`) → preference filter
   (transactional, so opt-out ignored) → regulatory filter → render each channel's template →
   dispatch → write a `notification_log` (final status `DISPATCHED`).
- **Proven by:** `Not01PipelineTest::test_customer_visible_account_status_change_notifies_the_customer`.

### Scenario B — an approval awaits a back-office group (ICN-01)
1. Any EM-CFG-04 request emits `ApprovalRequested` (topic `platform.approvals`).
2. `NotifyApproversOnApprovalRequested` → `StaffNotificationService::dispatch` with template
   `approval-needed`, **candidateGroup = the policy's approver role**. The group's members get an
   EMAIL/SLACK/IN_APP_PUSH notice (per each user's channel prefs + quiet hours).
- **Proven by:** `Icn01Test::test_pending_approval_notifies_the_approver_group`.

## 2. Data model (selected)
| Table | Purpose |
| --- | --- |
| `notification_routing_rule` | event_type → channels + purpose + urgency (the no-code routing) |
| `template` / `notification_template` | format-decomposed templates (EMAIL_SUBJECT/HTML/TEXT, SMS_TEXT, PDF) |
| `channel_operator_config` | per-channel adapter binding + sender |
| `customer_notification_preference` | opt-in/locale/quiet windows |
| `notification_log` / `notification_delivery_attempt` | the audit + per-channel attempt ledger |
| `render_failure_queue` | failed renders for retry |
| `Icn/*` (staff_notification, …group_membership, …user_pref, …delivery, …template) | ICN-01 staff pipeline |

## 3. Services
| Service | Responsibility |
| --- | --- |
| `NotificationOrchestrator` | NOT-01 pipeline: idempotency → route → preference → regulatory → render → dispatch → audit |
| `TemplateService` | template CRUD (Studio) |
| `RenderRetryService` / `RetryScheduler` / `BounceService` | render-failure retry, dispatch retry/escalation, bounce handling |
| `Icn/Services/StaffNotificationService` (+ directory/dispatcher) | ICN-01 group fan-out + per-recipient channel plan |

## 4. API surface
`/api/notifications/preferences`, `/api/admin/notifications/{send,resend,dashboard,failure-queue,
routing/pause}`, ICN staff catalog + inbox/ack endpoints. Guarded by `permission:notification.*`.

## 5. Integration (events)
- **Consumes (`OutboxEventPublished`) via bridges:** `DunningNotificationBridge` (DunningStageAdvanced),
  `TaxEventBridge`-adjacent, `AccountStatusNotificationBridge` (CustomerAccountStatusChanged),
  `NotifyApproversOnApprovalRequested` (ApprovalRequested → ICN).
- **Emits:** `Notification{Dispatched,Suppressed,Escalated,Undeliverable}`, ICN
  `StaffNotification{Created,Delivered,Acknowledged,Expired}`.

## 6. Processes
No BPMN; scheduled retry/escalation workers (`RetryDispatchCommand`, `RetryRenderCommand`, ICN
`StaffRetryCommand`/`StaffExpireCommand`).

## 7. Policy & config
Routing rules, templates, channel config, customer & staff prefs, ICN groups + adapter bindings — all
per-operator data (the seeders ship a WIK default; re-seed for a new country).

## 8. Cross-module dependencies
- **Reacts to →** Billing (dunning/tax), ILM (account status), Foundation Approvals (ICN).
- **Reads →** ILM/CRM for contacts. **Connector seam →** channel adapters (SMS/email/Slack/push).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-6 | an internal-only event with no routing rule produces no customer notice | `NotificationOrchestrator` |
| idempotency | a `source_event_id` is processed once | orchestrator (cache) |
| transactional override | TRANSACTIONAL notices ignore marketing opt-out | preference filter |

## 10. Open items / deltas
- `NotificationService` (legacy `send`) coexists with the orchestrator (back-compat) — not a duplicate.
- The approver-notification bridge + account-status bridge were wired during hardening (earlier the
  approval templates were orphaned).
