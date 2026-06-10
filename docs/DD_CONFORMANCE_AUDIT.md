# DD Conformance Audit

A living register that diffs the **implementation against the DDs**, module by module,
so gaps are tracked in one place instead of discovered one at a time. Replaces the
ad-hoc "explore the code and hope it matches" loop with a spec-first diff.

Legend: ✅ conformant · ⚠️ partial (table/scaffold present, behavior incomplete) ·
❌ missing · 🔁 fixed this pass. Severity: **H**igh (framework contract / money / state),
**M**edium, **L**ow.

> Full corpus coverage: see **`docs/DD_TRACEABILITY.md`** — all 134 design documents
> (256 PDFs incl. implementation guides) classified: 102 implemented, 8 partial,
> 10 per-operator variants (delivered by config engine), 2 deferred by decision
> (FOUNDATION_AUTH/Keycloak; external deep reporting), 0 missing,
> 12 reference docs.
>
> How this was built: each row was checked by reading the governing DD section and
> grepping the implementation. Modules marked "not yet fully audited" have not had a
> section-by-section diff and should not be assumed conformant.

---

## Subscription (SUB-WF-FRAMEWORK / SUB-LM / per-op DDs) — audited
See `docs/SUBSCRIPTION_FIDELITY_AUDIT.md` for the detailed table. Summary: framework
state machine, transient PENDING_* states, config-driven process keys, concurrency,
cancel/timeout/in-flight, pause-history, per-op config, SUSPEND-NP role gate — ✅.
Open: FOUNDATION_AUTH (local Sanctum), event-topic naming, R-FW-3 terminate
auto-interrupt, scheduled effective-timing. (M/L)

## Wallets (PLM-CFG-03 / BIL-05/06) — audited 🔁
- Wallet catalog (`wallet_catalog`, types, applicability, precedence, lifecycle, R-W rules) — ✅ 🔁
- Multi-wallet ledger keyed by `walletRef`; charge-time precedence/applicability resolver — ✅ 🔁
- Prepaid billing-intent settles from wallet (pay-from-balance + top-up-to-proceed) — ✅ 🔁
- Open: per-customer (vs per-subscription) wallet sharing; expiry sweep; points redemption at charge. (M)

## Provisioning (PROV-INT-01) — audited 🔁
- Per-target adapter resolution (`provisioning_adapter_config` → adapter_class) — ✅ 🔁
- Command ledger, broadcast/dispatch, desired/observed, reconcile — ✅
- **Force-sync approval** model (`provisioning_force_sync_request`: PENDING_APPROVAL →
  APPROVED → execute; R-PROV-07 approve-before-execute; audited events) — ✅ 🔁
- **Per-attempt ledger** (`provisioning_command_attempt`, §10.4: adapter, outcome,
  duration recorded per dispatch) — ✅ 🔁
- Open (M): full §9 status model (`RECEIVED/DISPATCHING/…/SUCCEEDED` vs current
  PENDING/SENT/CONFIRMED) + async `ASYNC_ACCEPTED` poll flow (coupled; deferred). ⚠️

## Billing money path (BIL-01/02/04/05) — audited
- Invoicing (assembler + gap-free legal number), payments (allocate→PAID, surplus→credit),
  dunning ladder (rules.billing.dunning → SUB-WF ops), billing-intent bridge — ✅
- **BIL-03 Cycle Close** — ✅ 🔁: per-subscription cycle anchor on the SUB-LM master
  (current_cycle_start/end, last_cycle_closed_window_end), opened at activation
  (R-BIL-03-V-2); `CycleCloseService` scanner (R-BIL-03-E-1/E-4) charges recurring +
  usage at the boundary, advances the anchor idempotently (R-BIL-03-C-5), audited in
  cycle_close_run; POSTPAID→cycle invoice (CycleClosed), PREPAID→wallet debit
  (CycleActivated) / freeze on shortfall (CyclePaymentMissed, R-BIL-03-C-3/W-1) with
  top-up unfreeze (R-BIL-03-W-4). Scheduled every 30 min.
- **BIL-01 charge model + BIL-02-GEN-01 structured invoices** — ✅ 🔁: `ChargeComputeService`
  returns typed charges per the DD schema (service_category_code distinguishes
  RECURRING `SUBSCRIPTION` from USAGE `VOICE/DATA/SMS`); line builder produces a
  SUMMARY/DETAIL hierarchy (parent_summary_line_id, sort_order R-GEN-01-L-1/L-4),
  resolves description_key via the operator translation catalog (R-GEN-01-L-5),
  computes per-line tax via PLM-CFG-02 → tax_breakdown + aggregated tax_summary
  (R-GEN-01-L-2); operator grouping policy (`invoice_grouping_config`: SINGLE/WALLET/
  PACKAGE/SERVICE_CATEGORY) may split into one invoice per group (R-GEN-01-C-3/4).
- **`customer_snapshot` (R-GEN-01-F-6)** — ✅ 🔁: `CustomerSnapshotService` captures an
  immutable copy of the customer (identity, tax category, preferred language, billing
  address) ONCE before charges, reused across grouped invoices; line wording resolves
  in the customer's language (R-GEN-01-L-5), tax uses the snapshot's category/location
  (R-GEN-01-L-2); a fetch failure writes NO invoice (no partial snapshot).
- **Triple-play + itemized usage** — ✅ 🔁: catalog-driven wallet routing (package vs
  PLM-CFG-01 USAGE service `default_wallet_ref`) so a WALLET grouping puts voice on its
  own invoice; RAT-01 mark-invoiced links each rated call to its invoice; GET
  /api/rated-events?invoice_id= returns the itemized call detail (destination, time,
  duration).
- Open vs BIL-02-GEN-01 (structural, tracked — NOT silently skipped): the five
  generator classes + `InvoiceGeneratorRegistry` + `InvoiceGenerationContext`
  (R-GEN-01-F-1/2) are collapsed into `InvoiceService::generateFromCharges` (one path,
  same outputs) — functional, not yet the registry structure; **generation failure
  queue** (rule group Q) and **bulk reversal** (rule group R) not built; **pro forma
  cycle generator** (PREPAID pre-cycle documents) not built; `invoice_line` table
  retains its pre-existing name (DD calls it `invoice_line_item`); RAT-01 uses
  `billed`+`invoice_id` rather than the `POSTPAID_PENDING_INVOICE`→`INVOICED` status. ⚠️
- **Cycle proration** — ✅ 🔁: CALENDAR cycles align the first cycle to the anchor day
  at activation; the recurring fee is charged pro-rata by whole-day ratio for any
  partial cycle (full cycles factor to 1.0).
- **BIL-02-GEN-01 generation failure queue (group Q) + bulk reversal (group R)** — ✅ 🔁:
  recoverable cycle-close failures persisted with context/backoff and resolved on a
  later success; operator-proposed, dual-controlled (approver≠proposer) batch cancel
  with eligible-vs-protected preview (signed-tax / cancelled / note-linked protected),
  per-invoice InvoiceCancelled events.
- Open (M): data/SMS rating uses code constants (usage-tariff catalog exists as
  fallback); payment reversal; account-credit auto-draw; pro-forma cycle generator
  (PREPAID pre-cycle documents). ⚠️

## Work Order (WO-01-FRAMEWORK) — audited 🔁
- Ticket source link (`source_type`/`source_ref`; Ticketing creates + waits for finalize) — ✅
- **Reassign + `wo_assignment_history`** (§1.7) — ✅ 🔁 (was ❌)
- **Structured notes + `wo_note_kind_registry`** schema validation (§1.3/§4.3) — ✅ 🔁 (was ⚠️ table-only)
- **2-step finalize** `IN_PROGRESS→FINALIZATION_PENDING→COMPLETED` + config-driven
  **finalize checklist** (`wo_finalization_requirements`, §3/§4.4) — ✅ 🔁 (was ❌; terminal renamed FINALIZED→COMPLETED)
- **Attachments** (`wo_attachment` + per-category min counts enforced in the finalize
  checklist, §1.4/§4.4) — ✅ 🔁
- Open (M/L): master/sub linkage (`master_wo_id`/`link_type`) actively used; SLA
  timestamp capture; skills-filtered auto-assign worker. ⚠️

## OSR — Stock & Equipment (OSR-01 / OSR-INSTANCE-01 / OSR-RMA-01) — audited
- Equipment vs material distinction (`equipment_sku.is_serialized` + `ownership_semantics`
  RETURNABLE/CONSUMABLE/RENTED; serialized→instance registry, non-serialized→quantity) — ✅
- Serialized instance lifecycle + RMA/EQP swap flow — ✅
- **Reservation lifecycle** (reserve → consume-as-INSTALL-movement → release) +
  **availability** API + event-driven **WO→install consumption** (WorkOrderFinalized
  consumes, WorkOrderCancelled releases) — ✅ 🔁 (was ❌). Cycle counts already present
  via `InventoryAuditService`.
- Open (M/L): `stock_reason_code` catalog as a first-class table; two-tier transfer
  helper; WO bill-of-materials (auto-reserve qty from the job's required SKUs).

## Ticketing (TCK-01) — audited 🔁
- Case lifecycle, SLA policy, ASR routing, WO creation + link — ✅
- **Config-driven category catalog** (`ticket_category_catalog`) + **WO-creation gating**
  (TCK-3 wo_allowed, TCK-7 terminal guard, one-active-WO) — ✅ 🔁
- **WorkOrderFinalized → ticket** resolution loop (§9.2, was claimed but unwired) — ✅ 🔁
- **Reopen** (RESOLVED→OPEN + reopened_count, §8.8) + **cancel** + comment visibility — ✅ 🔁
- **Gap-free `ticket_number`** (UNIQUE per operator/year), **`ticket_attachment`** metadata,
  **`ticket_link`** general multi-entity links, full §5 status set (TRIAGED / WAITING_* /
  UNDER_REVIEW) — ✅ 🔁
- Open (M/L): WAITING_* transition automation (status set present; auto-driven transitions light). ⚠️

## Catalog / SIP / PLM-CFG — audited 🔁
- PLM-CFG-01 services (+classes, consumption model, wallet/tax refs), SIP-01 packages
  (+versions, package_service composition, activate lifecycle), ILM-CFG-02 tech
  regions, RLM-CFG-01 homepass — ✅ (existing, test-covered)
- PLM-CFG-02 tax (tax_group/tax_rule + compute + fiscalised tax_invoice) — ✅
- PLM-CFG-04/SIP-03 discounts (catalog + assignment + compute; stackable flag) — ✅
- PLM-CFG-05/06/07 config catalogs (adjustment-type, equipment-type, voice/usage
  tariffs) — ✅
- **SIP-04 Bundle Launch** — ✅ 🔁 (was ❌ entirely): commercial_bundle + components +
  availability + discount rules + migration rules + auditable launch checks; lifecycle
  DRAFT→READY_FOR_REVIEW→APPROVED→ACTIVE→RETIRED with the validation gate
  (R-SIP-BUN-02/03/05); channel/franchise/region availability (R-SIP-BUN-10);
  migration-preview authorizes the path and names the owning SUB workflow
  (R-SIP-BUN-08/09).
- **SIP-05 Promotional Campaigns (MVP per DD)** — ✅ 🔁 (was a dead scaffold): offers /
  target rules / channels / participation tables; lifecycle; eligibility evaluation
  (window + channel governance + hard/soft target rules + participant cap); unique
  redemption binding a SIP-03 discount assignment. **Commercial Studio UI**
  (`/commercial/studio`): campaign designer (offers/targeting/channels/window/caps)
  with launcher (launch/pause/end) + live eligibility tester, and the bundle
  composer + launch pipeline (validate→review→approve→activate with the auditable
  checks displayed). 🔁
- **BIL-02-ADJ-01 / BIL-01-CN-01 invoice adjustments + note application** — ✅ 🔁
  (was the last missing money path): governed pipeline adjustment_request
  (FULL/LINE/AMOUNT scopes; operator reason-code catalog; adjustment_limits_config
  + /override-limit; audited adjustment_approval_step rows). Approval ROUTING is
  a rules-engine decision: `rules.billing.adjustment-approval` decision table
  (operator-overridable, FIRST hit — limit escalation / debit standard / auto
  small credit / dual control for large credits), answer {stepsRequired, ruleId}
  PINNED on the proposal at filing; config steps + auto_approve_under remain the
  registered fallback when no table is deployed → CREDIT_NOTE/DEBIT_NOTE invoices (CN-/DN- legal
  numbers, original_invoice_id) → note_application_ledger application: POSTPAID
  outstanding reduce/increase with surplus → account_credit_balance and FIFO
  auto-allocation; PREPAID wallet credit/debit where an insufficient wallet FAILS
  (never made negative) and /retry-application re-applies after top-up. Events:
  AdjustmentProposed/Approved/Rejected, Credit/DebitNoteIssued,
  Credit/DebitNoteApplied (per ledger row), DebitNoteApplicationFailed.
- **BIL-CFG-01 BillableEvent catalog** — ✅ 🔁 (was intent_type as free string):
  billable_event + billable_event_category, operator-scoped, DRAFT→ACTIVE→RETIRED,
  trigger taxonomy (SAGA_INTENT / LIFECYCLE_EVENT / ADMIN_ACTION /
  CUSTOMER_PURCHASE / EXTERNAL_PAYMENT / SCHEDULED), amount_sign_policy,
  applicability, pay-first + state-callback rules (R-B-6/SC-3), code/sign/trigger
  immutability once ACTIVE; admin CRUD at /api/billing/billable-events; runtime
  enforcement in BillingIntentService (unknown event rejected, applicability
  mismatch skips per R-B-5, sign-policy violations rejected).
- Open (M): SIP-02 launch-lifecycle depth (launch windows, EM-CFG-04
  approval hook for package/bundle launch — bundle approve is a direct endpoint
  today). Per-service wallet routing at charge time (needs subscription service
  lines). ⚠️

## Foundation / Auth (EM-CFG-03 / FOUNDATION_AUTH) — known divergence
Local Sanctum + spatie/laravel-permission instead of Keycloak/OIDC. Acknowledged top
divergence; documented in CODE_TOUR.md. ⚠️ (by decision)

## Notification (NOT-01) — partially audited 🔁
Send + internal comms present. **Template studio** added: per-channel
`notification_template` catalog + `{{var}}` rendering wired into send, `invoice_template`
layouts, CRUD/preview API, and a Vue studio page (`/templates/studio`) — ✅ 🔁
Open: delivery-provider integration (SMS/email gateways stubbed); per-locale fallbacks. ⚠️

## Ilm / Customer (ILM-CFG-01) — audited 🔁
- Customer/Account separation, KYC two-level approval, account_number + payment ref,
  service_class / attention_banner, CVM activity — ✅
- **Account flag system** (`customer_account_flag_catalog` + `customer_account_flag`,
  §3.5): operator-extensible NPD/churn/fraud flags; NPD surfaces the attention banner;
  set/clear API + events — ✅ 🔁
- **Sub-status registry** (`customer_sub_status_catalog`): sub-status validated against
  the operator catalog — ✅ 🔁
- Open (M/L): daily Drools flag-evaluator worker; account_status_history; multi-slot ID
  search. ⚠️

## Fulfillment (FUL-02) — audited 🔁
- **Order journey AS CONFIG** (FUL-02-FRAMEWORK §1.1): capture starts the seeded
  `ful-order-capture` process (order stores `process_instance_id`); steps are
  external-task topics (order-validate / create-subscription / create-install-wo /
  kyc-gate / trigger-activation / complete); install + KYC are message catches
  correlated by WorkOrderFinalized / final CustomerKycApproved (or the desk API);
  cancel interrupts the instance (§1.8). Extension = editing the flow in the
  Workflow Studio, not code — ✅ 🔁 (was hardcoded orchestration)
- **STEP-KYC activation gate** (order cannot activate until KYC APPROVED) — ✅ 🔁
- Open (L/M): wait-payment messageCatch + payment-timeout boundary (PaymentReceived
  correlation for pay-first orders); explicit per-step failure rows.

## FOUNDATION_CACHE — audited 🔁
- **Cache-aside foundation** (`SophixCache`): `sophix:{module}:{aggregate}:{id}` keys
  (CACHE-KEY-1..4), TTL on every value (catalog 24h / pricing 1h), store errors are
  cache MISSES — a business request never fails on cache (CACHE-READ-1/2), only
  successful source results cached (CACHE-READ-4). Store swappable via Laravel cache
  config (array/file dev, Redis cluster per §4 in prod) — ✅ 🔁 (was entirely missing)
- **Consumers** (a module never caches its OWN data): Billing←PLM wallet catalog
  (per-charge `sophix:plm:wallet:{op}:{code}` + catalog set, 24h) and Rating←PLM
  voice/usage tariffs (1h pricing) — ✅ 🔁
- **Event-driven invalidation** (§9): Wallet* events evict the consumer's copies
  (lazy evict; TTL safety net) — ✅ 🔁
- **Admin ops** (§11): POST /admin/cache/invalidate + GET /admin/cache/stats — ✅ 🔁
- Open (L): prefix SCAN invalidation (Redis-driver concern); hit-rate alerting (§13);
  more consumer wirings (e.g. FUL reading service/package defs) as hot paths emerge.

## Rbac (EM-CFG-03) — audited 🔁
- Role + permission catalog (runtime CRUD), role-permission matrix, user role
  assignment, **effective-access API** — ✅ (on the local spatie stack).
- **RBAC Admin portal** (`/admin/rbac`): role⇄permission matrix editor, runtime
  role/permission creation, user directory + role assignment with computed effective
  access, change-audit viewer — ✅ 🔁
- **`rbac_change_audit`** (§8.8): immutable before/after audit on every catalog edit,
  matrix sync and user assignment, with the acting admin; queryable feed — ✅ 🔁
- Open (by decision): the EM-CFG-03 **scope system** (operator/franchise/region/
  contractor/team/channel) + frontend-action matrix. Part of the **FOUNDATION_AUTH
  divergence** (local Sanctum/spatie vs Keycloak/OIDC — which is also the designed
  LDAP/AD federation point); scope enforcement touches every endpoint — deferred. ⚠️

## Workforce (EM-02) / Reporting (RPT) / ItOps — functional, not deep-diffed
Registry/platform modules with passing tests: Workforce = contractor/team/staff
registry; Reporting = daily-metric CSV export + reconciliation; ItOps = service
control / heartbeat / system-log. No section-by-section DD diff; no obvious behavioral
gaps surfaced. ⚠️ (low risk)

## NOC console + Warehouse backoffice — added 🔁
- **NOC console** (`/noc`): single-pane overview (service heartbeats with
  start/stop/restart via service_control, running flows, workflow incidents,
  provisioning mismatches, outbox backlog, SLA-overdue tickets), system-log viewer
  with level/channel filters, **end-to-end trace** — one key (subscription/order/
  operation/correlation id) reconstructs the time-ordered journey across outbox
  events, workflow instances + tasks, provisioning commands, notifications and
  logs (every record carries correlation_id by foundation design). ✅ 🔁
- **Warehouse backoffice** (`/warehouse`): stock balances (on-hand/reserved/available)
  + movement posting, PO approve/receive pipeline, serialized-equipment state
  transitions (full RMA state set now accepted by the API), swap/RMA list. ✅ 🔁
- SLA: ticket SLA is data-driven (sla_policy + sla_due_at) and breaches now surface
  in the NOC (count + list). WO-level SLA = timestamp capture only; calculation is
  the future FUL-OPS-SLA DD per WO-01 — unchanged deferral. ⚠️

## Field audit (FA-01/02/03)
Implemented (`FieldAuditService` + tests); depth not separately diffed. ⚠️ (low)

---

## Fixed this pass (🔁)
1. Wallet catalog + multi-wallet ledger + per-service routing (PLM-CFG-03).
2. Prepaid billing-intent settlement from wallet (BIL-01).
3. Per-target provisioning adapter resolution (PROV-INT-01 §10.2).
4. WO-01 framework: reassign + assignment history, structured notes + kind registry +
   schema validation, 2-step finalize + config-driven checklist (terminal → COMPLETED).
5. OSR-01 stock reservation + WO→install consumption.
6. PROV-INT-01 force-sync approval lifecycle.
7. BIL-02 automatic cycle billing (rated_event → invoice/wallet).
8. PLM-CFG-07 usage_tariff catalog (data/SMS rating is config, not constants).
9. WO-01 attachments + checklist attachment enforcement.
10. PROV-INT-01 per-attempt ledger (§10.4).
11. NOT-01 template studio (per-channel notification templates + invoice layouts + UI).
12. TCK-01 category catalog + WO-gating + WO-finalized loop + reopen.
13. ILM-CFG-01 account flag system (§3.5) + sub-status registry.
14. FUL-02 STEP-KYC activation gate.
15. FUL-02 order journey as config (ful-order-capture process + topic handlers + message catches).
16. FOUNDATION_CACHE (cache-aside foundation, Billing/Rating consumers, event eviction, admin ops).
17. EM-CFG-03 RBAC Admin portal (/admin/rbac) + rbac_change_audit (§8.8) + user directory.
18. SIP-04 Bundle Launch (full: lifecycle, launch checks, availability, migration paths).
19. SIP-05 Promotional Campaigns MVP (eligibility, channels, unique redemption → SIP-03 binding).
20. SIP-04/05 Commercial Studio UI (campaign designer + launcher + eligibility tester; bundle launch pipeline).
21. NOC console (/noc): overview + start/stop + logs + end-to-end correlation traces + SLA breaches.
22. Warehouse backoffice (/warehouse) over the existing OSR services.
23. Customer 360 (/customers/{id}) + Ticketing cockpit (TCK-01 §13: queues, SLA indicators, timeline, actions).
24. Operator deployment config (identity/locale/currency/theme/log level) + runtime theming in the shell.
25. Setup wizard (sophix:setup demo|preprod|prod) incl. demo journey seeder (verified end-to-end).
26. i18n foundation: per-operator backend locale (Content-Language, X-Locale override), frontend t()/money()/date formatters on en-KE/sw-KE/fr-SN conventions; notification templates already per-locale. Extended with the **i18n resource catalog + Localization Studio** (`/i18n/studio`): ui_translation rows addressed by (culture, domain, section, key), global or per-operator scope (operator overrides global), new cultures addable from the UI (e.g. fr-SN, en-UG), missing-key worklist per culture, cached merged map consumed by frontend t() (shared as i18nResources) AND merged into Laravel's translator per request so backend __() strings honour studio edits without a deploy.
27. BIL-02-ADJ-01 + BIL-01-CN-01: governed adjustment pipeline (proposal → audited approval → credit/debit-note issuance → application across POSTPAID invoices/credit balance and PREPAID wallets, append-only ledger, failed-application retry).
28. BIL-CFG-01 BillableEvent catalog (event + category tables, admin API, runtime intent enforcement) — last MISSING corpus items closed; zero tracked-missing DDs remain.

## Remaining gaps, prioritized
1. ~~OSR-01 stock reservation + WO→install consumption~~ — ✅ done this pass.
2. **Provisioning** force-sync approval ✅ done; remaining §9 status model + async + attempt ledger (M).
3. ~~Automatic cycle billing~~ — ✅ done (usage). Remaining: recurring package-fee generation (M).
4. ~~WO attachments + checklist attachment requirements~~ — ✅ done.
5. **Per-service wallet routing consumed at charge time** (M).
6. ~~Usage-tariff catalog for data/SMS rating~~ — ✅ done.
7. Full DD diff for Ticketing, Catalog depth, Ilm/CVM, Fulfillment (unknown).
