# SOPHIX V3 — Feature Traceability & Code Exploration Matrix

Forked from **`DD_CROSS-00-System_Traceability_and_Implementation_Map-v1.0`** (§5
Feature Traceability Matrix) and kept current against the implementation. The
original asks five questions per feature (who uses it, who owns the command, who
reads, which events, how is it tested end-to-end); this fork adds the answers as
they exist **in this codebase** plus the features delivered beyond the original
list, and a tour log so the same document drives our code-exploration sessions.

Maintenance rule (mirrors DD_CROSS-00 §1): a feature is not "done" until its row
names the owning command code path, its event integration, and a passing E2E
test. A row is not "understood" until its Tour column is checked.

Companion documents: `docs/DD_TRACEABILITY.md` (per-DD status, all 134 DDs) ·
`docs/DD_CONFORMANCE_AUDIT.md` (module-by-module spec diff).

Legend — Tour: ✅ explored & discussed · ⬜ pending. Status: per DD_TRACEABILITY.

---

## 1. Feature matrix (forked §5 + added rows)

### 1.1 Customer acquisition & fulfillment

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 1 | Lead → customer conversion (sales) | SALES-01 | Full pre-order pipeline: `sales_territory`, rich `sales_lead` lifecycle (DRAFT→NEW→ASSIGNED→CONTACTED/FOLLOW_UP→QUALIFIED→CONVERTED/LOST/DUPLICATE), `sales_lead_assignment` history, `sales_activity`, `sales_lead_package_interest`, `sales_conversion`, immutable `sales_attribution_event`. `SalesService` (createLead: lead_number, territory→franchise routing, duplicate detection SALES-1/7, auto-assign; assign SALES-6; activity-driven progression; qualify; convertToOrder→FUL-02 capture + attribution SALES-2/5); daily-work for FE-APP-02. Legacy `LeadController` kept | SalesLeadCreated/Assigned/Qualified/Converted/Lost, SalesAttributionRecorded | `Sales01PipelineTest` (6), `Wave3SalesUssdTest` | ✅ |
| 2 | New customer sale to activation | FUL-02 order capture | `Modules/Fulfillment/Services/OrderCaptureService::capture` → seeded `ful-order-capture` process; 6 handlers in `Modules/Fulfillment/app/Workflow`; KYC gate + install-WO message catches | OrderCaptured, CustomerKycApproved, WorkOrderFinalized, SubscriptionActivated | `FulfillmentJourneyTest` (5 journeys incl. KYC park/resume); `DemoJourneySeeder` end-to-end | ✅ |
| 3 | Customer 360 (composition screen) | read-only composition | `CustomerOverviewService` one-round-trip aggregator (profile/accounts+flags/subscriptions/billing/tickets/interactions/notes) with **per-panel isolation** — a failing module degrades only its panel; `GET /api/customers/{id}/overview`; `Show.vue` rewired to it | consumes module events indirectly | `CustomerOverviewTest` (3, incl. partial-failure) | ✅ |
| 4 | Customer/account/KYC master (ILM) | ILM-CFG-01 | `AccountService` (catalog-driven sub-status: derives main status, enforces requires_approval R-ILM-S-2, carries affects_provisioning), `CustomerService` (KYC state machine + config-driven approval authority per level R-ILM-K-3), flag system (Drools catalog) | CustomerKycApproved, CustomerAccountStatusChanged, flag events | `AccountFlagTest`, `CustomerApiTest`, `CvmFlagEvaluatorTest` | ✅ |
| 5 | HomePass / serviceability (RLM) | RLM-CFG-01 | `Modules/Catalog` HomePass + `homepass_status_code` config catalog (semantic flags, not a hardcoded enum); `CatalogService::changeHomePassStatus` (flag-driven, first-sellable `HomePassReachedSellable` R-RLM-CFG-01-H-6); `/homepass/eligible` reads is_sellable | HomePassReachedSellable, StatusChanged | `CatalogApiTest` | ✅ |

### 1.2 Subscription lifecycle (framework + MACD)

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 6 | Subscription framework (states, ops, concurrency) | SUB-WF-FRAMEWORK / SUB-LM-01 | `Modules/Subscription/Services/SubscriptionOperationService`; process keys per operation config; status catalog | SubscriptionOperation* | `SubscriptionApiTest`, framework fidelity audit | ✅ |
| 7 | Activation | SUB-WF-ACTIVATE-01 | seeded `sub-activate` flow + ValidateActivationHandler (rules) | SubscriptionActivated | `SubscriptionApiTest`, `OperationFrameworkTest` | ✅ |
| 8 | Pause / resume / terminate | SUB-WF-PAUSE/RESUME/TERMINATE | seeded flows + per-op config (PAUSE_FEE / RECONNECTION_FEE intents) | operation events | `SubscriptionPauseResumeTest`, `SubscriptionRestrictionTest` | ✅ |
| 9 | Upgrade / downgrade (+ prepaid wallet settlement) | SUB-WF-UPGRADE/DOWNGRADE | flows + `BillingIntentHandler` (proration via rules + intents); prepaid parks on AWAITING_PAYMENT until topup (`ConfirmPrepaidIntentOnTopup`) | BillingIntent*, WalletDebited | `SubscriptionUpgradeTest`, `SubscriptionPrepaidUpgradeTest` | ✅ |
| 10 | Restrict / suspend-NP / relocation / migration | SUB-WF-* | seeded flows; suspend-np triggered by dunning; relocation/migration validated by rule tables | operation events | `SubscriptionSuspendNpTest`, `SubscriptionRelocationTest`, `DunningTest` | ✅ |

### 1.3 Billing & money

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 11 | Billable-event intents (charge gateway) | BIL-01 / BIL-CFG-01 | `Modules/Billing/Services/BillingIntentService::emit` — catalog-validated, wallet/invoice/credit rails | SubscriptionBillingIntent* | `BillableEventCatalogTest`, upgrade tests | ✅ |
| 12 | BillableEvent catalog (admin) | BIL-CFG-01 | `BillableEventCatalogService` + `/api/billing/billable-events` (DRAFT→ACTIVE→RETIRED, trigger taxonomy, sign policy) | BillableEventCatalogChanged | `BillableEventCatalogTest` | ✅ |
| 13 | Invoice generation + legal numbering | BIL-02 / BIL-02-GEN-01 | `InvoiceService::generate` / `issueNote`; gap-free `invoice_sequence` (row-locked per operator/year/type) | InvoiceGenerated | `BillingApiTest` | ✅ |
| 14 | Cycle billing (recurring) | BIL-03 | `CycleBillingService` + `sophix:billing:run-cycle` (usage settlement; no cycle anchor / recurring fee yet) | SubscriptionCycleBilled | `CycleBillingTest` | ✅ |
| 15 | Mediation + usage rating | MED-01 / RAT-01 | `MediationRatingService` (dedupe, voice/usage tariff catalogs) | UsageRated | `MediationRatingTest`, `UsageTariffTest` | ✅ |
| 16 | Payments + allocation + gateway | BIL-01-PAY-01 / PAY-GW-01 | `PaymentService` (FIFO/directed, surplus credit), `Modules/PaymentGateway` webhook | PaymentReceived/Applied | `BillingApiTest`, `GatewayCallbackTest` | ✅ |
| 17 | Wallet & top-up (multi-wallet) | BIL-05/06 + PLM-CFG-03 | `WalletService` (catalog-driven wallets, precedence draining, cached catalog) | Wallet* | `WalletMultiWalletTest`, `WalletCatalogTest` | ✅ |
| 18 | Dunning ladder → suspension | BIL-04 | Versioned **`dunning_program` catalog** (level_definitions JSONB: grace + action_workflow_intent + action_payload per level) is the authoritative policy surface, pinned per `dunning_state` at entry (C-1); `DunningProgramResolver` (operator + billing_mode + most-specific); `DunningService` program-driven assess (monotonic advance D-2, multi-restriction R-4 tracked in applied_restriction_codes, recovery R-1..R-3 reverse-order removal + resume, T-4 review gate, archive D-4); `dunning_state_archive`; admin REST (advance/hold/clear-without-payment/force-terminate/extend-review/refresh-debt/pending-review/history) + `DunningProgramController` (new-version retires prior); retry/archive sweeps | SubscriptionEnteredDunning, DunningStageAdvanced, DunningDebtIncreased, DunningCleared, DunningTerminationPending, DunningRecoveryFailed, DunningAdminOverride, SubscriptionSuspendedForNonPayment | `DunningTest` (9) | ✅ |
| 19 | Tax invoice & fiscalisation | BIL-02-TAX-01 | Payment-triggered (`TaxEventBridge`: PaymentApplied/WalletToppedUp/PaymentReceived, per-operator `tax_operator_config` gate T-4, idempotent T-5); `TaxInvoiceGenerator` (proportional POSTPAID G-3 / inclusive PREPAID decomposition via PLM-CFG-02 G-4/G-5, gap-free TAX_INVOICE numbering); async signing state machine GENERATED→PENDING_SIGNATURE→SIGNED\|SIGNING_FAILED→GAVE_UP_AUTO via config-driven pluggable `TaxInvoiceSigner` (`TaxSignerRegistry`); transient backoff retry→give-up, validation parks for admin (F-3), `tax_invoice_signing_failure` audit; cancel (unsigned immediate / signed dual-approval TAX_COMPLIANCE_OFFICER C-1/C-2), re-sign, resolve-no-action, manual, dashboard; sign/retry scanners | TaxInvoiceIssued, TaxInvoiceSigned, TaxInvoiceSigningFailed, TaxInvoiceSigningGaveUp, TaxInvoiceCancelled | `Tax01Test` (10), `TaxComputeTest` | ✅ |
| 20 | **Adjustments → credit/debit notes** (added) | BIL-02-ADJ-01 / BIL-01-CN-01 | `AdjustmentService` (scopes, limits, rules-routed approval pinned per proposal) → `InvoiceService::issueNote` → `NoteApplicationService` (ledger) | AdjustmentProposed/Approved, Credit/DebitNoteIssued/Applied, DebitNoteApplicationFailed | `AdjustmentTest` (10) | ✅ |
| 21 | Discounts at billing time | SIP-03 / DIS-OP-01 | **SIP-03** assignment lifecycle: `DiscountAssignmentService` (create→DRAFT/PENDING_APPROVAL/ACTIVE, R-DA-01 catalog check, R-DA-05 duplicate guard, R-DA-07/11 EM-CFG-04 approval + callback activation, R-DA-08 cancel future-only, expiry sweep, status_history, effective-query); validity windows + reason/source/priority/stacking_group. **DIS-OP-01** `DiscountComputeService` honours status=ACTIVE + valid window + stacking-group highest-priority + stackable accumulate. SIP-03 never touches money | DiscountAssignmentCreated/ApprovalRequired/Activated/Cancelled/Expired/Rejected | `DiscountAssignmentTest` (7), `DiscountComputeTest` (2) | ✅ |

### 1.4 Field operations & inventory

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 22 | Work order lifecycle (framework) | WO-01 | `WorkOrderService` (fixed state machine, reassignment, structured notes, 2-step finalize + checklist) | WorkOrderAssigned/Finalized/Cancelled | `WorkOrderFrameworkTest` | ✅ |
| 23 | Provisioning dispatch / retry / reconciliation / force-sync | PROV-INT-01 | `ProvisioningService` + `ProvisioningAdapterRegistry` (per-target adapters), attempt ledger, `ReconciliationService` approval flow | ProvisioningCommand* | `AdapterRoutingTest`, `ReconciliationTest` | ✅ |
| 24 | Stock chain + reservations | OSR-01 | `Modules/Osr/Services/StockService` (reserve→consume on WO finalize; R-OSR-SC-8 no-negative, SC-9 approver for write-off/cycle-count, SC-4 two-tier transfer guard, SC-7 reservation expiry sweeper) | StockMoved/Reserved/Consumed/Released/Expired | `StockReservationTest`, `StockRulesTest` | ✅ |
| 25 | Equipment instances, swap, RMA | OSR-INSTANCE / OSR-RMA-01 | `EquipmentInstanceService` state machine + swap/RMA flows; INST-2 serialized-only, INST-5 event_sequence, INST-7 contractor_id on recovery (the routing fix), INST-9 terminal active=false; named lifecycle events | Equipment* (Recovered/Bound/Unbound/Decommissioned) | `OsrApiTest`, `SwapRequestTest` | ✅ |
| 26 | Procurement, receipt, audit, write-off | OSR-02/05 | `ProcurementService` (PO → approve → receive → OSR-01 movement + OSR-INSTANCE register for serialized, R-OSR-02-08), `InventoryAuditService` (count→variance→idempotent reconcile, R-OSR-05-09) | StockMoved | `ProcurementAuditTest` | ✅ |
| 27 | Warehouse backoffice (added) | UI over OSR | `resources/js/Pages/Osr/Warehouse.vue` | — | UI route test | ⬜ |
| 28 | Field audits | FA-01/02/03 | Unified capability (audit_type-driven per DD MVP baseline). `FieldAuditCampaignService`: campaign→task→expected-item snapshot→observation (idempotent by offline ref)→**expected-vs-observed comparison** raising typed discrepancies (MISSING/WRONG_SERIAL/DAMAGED/FOUND_EXTRA/WRONG_LOCATION/NOT_ACCESSIBLE)→`rules.field_audit.<type>.discrepancy` severity+route→route (CREATE_TICKET/RMA_RECOVERY emit; OSR_CORRECTION/WRITE_OFF gated by EM-CFG-04)→auto-close on clean. FA never mutates OSR itself (boundary). Flat `field_audit` kept as the lightweight no-WO path | FieldAuditTaskCreated/Closed, FieldAuditDiscrepancyOpened/Routed | `FieldAuditCampaignTest` (6), `FieldAuditTest` (2) | ✅ |
| 29 | Contractor / staff / team registry | EM-02 | `Modules/Workforce`: registry + capacity hot path — `ContractorAvailabilityService` (region-scope/skill/slot resolution R-EM-CS-1..6, atomic slot commitment R-EM-CS-6/7) | ContractorSlotCommitment* | `WorkforceApiTest` | ✅ |

### 1.5 Care, assurance & engagement

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 30 | Ticket lifecycle + SLA + ticket→WO | TCK-01 (+ ASR satellites) | `TicketService` (TCK-2 link rule, category catalog gating, one-active-WO, TCK-6 `ticket_link` on WO create, canonical `WAITING_WORK_ORDER`, WO finalized→RESOLVED/UNDER_REVIEW + WO cancelled→review loop, first-response SLA) | TicketCreated/TicketWorkOrderCreated/Reopened | `TicketApiTest`, `AsrTest` | ✅ |
| 31 | Ticketing cockpit UI (added) | UI over TCK | `resources/js/Pages/Tickets/Index.vue` (queues, SLA, timeline, actions) | — | — | ⬜ |
| 32 | Notifications + templates studio | NOT-01 / ICN-01 | **NOT-01** full model: 7 tables (`template`/`notification_routing_rule`/`channel_operator_config`/`customer_notification_preference`/`notification_log`/`notification_delivery_attempt`/`render_failure_queue`); `NotificationOrchestrator` pipeline (idempotency→route→preference→regulatory→render PDF+channel artifacts→dispatch→audit); config-driven pluggable `ChannelAdapter` (Email/SMS) + failure policy (retry/backoff→escalate, fallback, template-disable, bounce); admin/customer REST; `Studio.vue` drives the versioned `template` model. **ICN-01** staff fabric: 7 tables + `staff_group_membership`; candidate-group fan-out→effective channel list; config-driven `StaffChannelAdapter` (EMAIL/SLACK/MSTEAMS/IN_APP_PUSH); 8-step dispatch, 3 fallback modes, ACK suppression, retry/expiry sweeps | PdfReady, NotificationDispatched/Suppressed/Escalated/Undeliverable, BounceProcessed; StaffNotificationCreated/Delivered/Acknowledged/DeliveryFailed/FailedToReachAnyone/Expired/TemplateRenderWarning | `Not01PipelineTest` (17), `Icn01Test` (17), `TemplateStudioTest`, `NotificationApiTest` | ✅ |
| 33 | CVM retention | EM-03 | Full 5-table model: `cvm_customer_signal_profile`, `cvm_segment_membership`, `cvm_activity` (DD shape), `cvm_offer_instance`, `cvm_outcome`. `CvmEvaluationService` (refresh signals incl. live dunning level → rules.cvm.segmentation → segment + churn score + spawn activity); `CvmActivityService` (idempotent-by-source-event create, CUST-INT-01 interaction writes, close→outcome); `CvmOfferService` (propose → EM-CFG-04 approval above threshold via rules.cvm.offer; accept → calls SIP-03 DiscountAssignment, never DIS directly → outcome + APPLIED). Boundaries honored (no direct discount/notify/sub-mutation) | CvmCustomerEvaluated, CvmActivityCreated, CvmActivityClosed, CvmOfferProposed, CvmOfferAccepted, CvmOfferApplied | `CvmTest` (5), `CvmFlagEvaluatorTest` | ✅ |
| 34 | Campaigns designer + bundles launch | SIP-04 / SIP-05 | `BundleService` (launch-check pipeline, lifecycle, availability, migration-preview→SUB), `CampaignService` (eligibility, unique participation→SIP-03 assignment, validate-gated activation R-SIP-CAMP-02/03/04/05); `Catalog/Commercial.vue` | Bundle*/Campaign* | `BundleAndCampaignTest` (7) | ✅ |
| 35 | Catalog setup (service/package/tax/wallet/tariff) | PLM/SIP/BIL config | `Modules/Catalog` models + seeders; cache-aside consumers | CatalogChanged (evicts) | `CacheFoundationTest`, catalog tests | ⬜ |

### 1.6 Platform & cross-cutting

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 36 | Workflow engine (definitions as data, external tasks, message catches) | FOUNDATION_CAMUNDA (local engine) | `Modules/Workflow`: ProcessDefinition graphs, TaskRegistry, `sophix:workflow:work`, correlateMessage | — | `WorkflowEngineTest` | ✅ |
| 37 | Rules engine (decision tables, operator override) | FOUNDATION_DROOLS (local engine) | `DataDrivenRuleEngine` (FIRST/COLLECT, fallbacks, operator-scoped tables) | — | `DecisionTableTest`; `AdjustmentTest` routing | ✅ |
| 38 | Outbox/inbox event bus | FOUNDATION_KAFKA (local) | `EventBus` → outbox_events → `sophix:outbox:dispatch` → listeners; correlation ids | all | event tests throughout | ✅ |
| 39 | Cache foundation | FOUNDATION_CACHE | `SophixCache` (cache-aside, TTLs, event eviction, admin invalidate/stats) | — | `CacheFoundationTest` | ✅ |
| 40 | RBAC + admin portal + change audit | EM-CFG-03 | Spatie is the enforcement engine; layered with the DD catalog: **user scopes** (`rbac_user_scope_assignment` OPERATOR/FRANCHISE/TECH_REGION/CONTRACTOR/TEAM/CHANNEL/GLOBAL) + `RbacScopeService.withinScope` enforcement helper (GLOBAL/operator/exact match) modules call for scope_required perms; role/permission **metadata** (family/risk/scope_required); **frontend-action matrix** (`rbac_frontend_action` → per-user navigation filtered by permission, UI-only); effective-access now returns roles+permissions+scopes; `rbac_change_audit` + RBAC events | RbacUserScopeAssigned/Revoked, RbacUserRoleAssigned | `RbacApiTest` (10) | ✅ |
| 41 | Approval workflow catalog | EM-CFG-04 | `ApprovalService` (threshold-gated policy + N-approval; APR-6 segregation of duties via allow_requester config, APR-5 approver-role eligibility, APR-7 immutable approval_decision); `ApprovalController` | Approval* | `FileAndApprovalTest` | ✅ |
| 42 | NOC console + end-to-end traces | ops (added) | `NocController` (overview, service control, correlation-id trace across events/instances/tasks/commands/logs/notifications); `ItOps/Noc.vue` | — | `NocConsoleTest` (4) | ⬜ |
| 43 | Operator config (identity/locale/currency/theme/logs) | config requirement (added) | `operator_config` + `OperatorConfigController`; runtime theming in shell | — | `OperatorConfigTest` | ✅ |
| 44 | **i18n resource catalog + Localization Studio** (added) | config requirement | `ui_translation` + `TranslationService` (merged map → translator + `i18nResources`); `I18n/Studio.vue` | — | `I18nResourceTest` (3) | ✅ |
| 45 | Setup wizard (demo/preprod/prod) | ops (added) | `sophix:setup` + `DemoJourneySeeder` | — | verified journeys | ✅ |
| 46 | Reporting (thin; deep = external) | REP-01 | `Reports/Dashboard.vue` + read APIs | consumes events | — (deferred by decision) | ⬜ |

### 1.7 Channels (frontend DDs)

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 47 | Backoffice web app (13 surfaces) | FE-APP-01 | Inertia pages under `resources/js/Pages` | — | route/UI tests | ⬜ |
| 48 | Field PWA (sales + contractor) | FE-APP-02/03 | `/m` shell + `resources/mobile` app; installable (manifest + SW scope /m, offline shell) | — | `PwaShellTest` | ✅ |
| 49 | Customer self-care PWA | FE-APP-04 | `/care` shell + `resources/care`; own manifest/scope; selfcare API group | — | `PwaShellTest`, selfcare tests | ✅ |
| 50 | USSD channel adapter | FE-CH-USSD-01 | **Config-driven menus** (`ussd_menu_definition` per operator+language — menus are data) + richer `ussd_session` (state machine, language) + `ussd_request_log` trace. `UssdService` menu engine walks the menu graph statefully and dispatches leaf actions to owning module reads (balance+BIL-04 dunning, subscription) / writes (TCK-01 ticket, idempotent per session) — never applies payment / changes sub / closes ticket locally; records CUST-INT-01. Normalized `POST /api/channels/ussd/sessions` + legacy webhook; en/sw seeded | — (channel adapter; logs interactions) | `UssdChannelTest` (7), `Wave3SalesUssdTest` | ✅ |

---

## 2. Minimum E2E test pack (DD_CROSS-00 §12) → our suite

| Cross-map test | Our proof | State |
|---|---|---|
| New WIK customer activation | `FulfillmentJourneyTest` + `DemoJourneySeeder` (capture → KYC → WO → ACTIVE) | ✅ |
| Payment idempotency | `BillingApiTest` (Idempotency-Key middleware on /payments) | ✅ |
| Ticket to support WO | `TicketApiTest` (gating, one-active-WO, finalize→resolve loop) | ✅ |
| Dunning suspension | `DunningTest` (ladder → suspend-np flow → restriction) | ✅ |
| Relocation | `SubscriptionRelocationTest` (target HomePass validation → WO) | ✅ |
| Equipment swap | `SwapRequestTest` (serials, stock movement, instance states) | ✅ |
| Customer 360 partial failure | `CustomerOverviewService` per-panel isolation (`CustomerOverviewTest`: a throwing panel degrades alone) | ✅ |
| Reporting reconciliation | `ReportExportReconcileTest` (dashboard totals vs source modules) | ✅ |

## 3. Tour log

| Date | Items | Notes |
|---|---|---|
| (earlier sessions) | #6–#10 MACD framework; #17 wallets; #15 mediation/rating; #11 prepaid intents; #23 provisioning; #22 WO framework; #2 order capture | style: mental model → flows → config knobs → config-based extension recipes → connections |
| 2026-06-09 | #20 adjustments/notes; #12 billable-event catalog | incl. rules-routed approval (#37 partially) |
| 2026-06-10 | #39 cache (earlier), #43–#45, #48–#49 verified | PWA installability fixes |
| 2026-06-10 | #38 outbox/event bus (confirmed prior coverage); #36 workflow engine | engine internals: token walk, fetchAndLock, tick |
| 2026-06-10 | #37 rules engine | table anatomy, hit policy, fallbacks, 14 consumers |
| 2026-06-10 | #13 invoice generation + legal numbering | gap-free row-locked sequence; STA-01 lazy-status gap noted |
| 2026-06-10 | #14 cycle billing | rate→billed-flag→settle by mode; gaps found |
| 2026-06-10 | #14 GAP CLOSED | BIL-03 per-subscription anchor + recurring package fee; separated cycle-vs-usage (ChargeComputeService typed charges); BIL-02-GEN-01 SUMMARY/DETAIL lines + operator grouping policy |
| 2026-06-10 | #16 payments | PAY-GW-01 doorway (dedupe/resolve) → BIL-01-PAY-01 owner (FIFO/directed alloc, surplus credit, dunning clear); gaps: reversal, credit auto-draw |
| 2026-06-12 | #30 ticketing/SLA, #24 stock, #25 instances, #26 procurement, #34 bundles/campaigns, #4 ILM, #29 EM-02 capacity, #41 EM-CFG-04 approvals, #5 RLM HomePass | built whole per DD then code-first toured (correctness + L2/L3 altitude audit) |
| 2026-06-12 | #32 NOT-01 + ICN-01 | both built whole (7+7 tables); config-driven pluggable channel adapters; full pipeline/dispatch/failure/ACK; studio aligned to versioned `template` model; 34 new tests (suite →366) |
| 2026-06-12 | #18 dunning (BIL-04) | replaced decision-table policy with the DD's versioned `dunning_program` catalog (level_definitions, version pinning); multi-restriction + reverse-order recovery; archive; full admin/program REST (suite →368) |
| 2026-06-12 | #19 tax (BIL-02-TAX-01) | payment-triggered tax invoices; async signing state machine via config-driven pluggable signer; retry/give-up/validation; dual-approval cancel; Dunning channel fixed to NOT-01 routing + Dunning Program Studio (suite →379) |
| 2026-06-12 | #33 CVM (EM-03) | full 5-table CVM (signal profile, segmentation, activities, offers w/ EM-CFG-04 approval, outcomes); rules-based segmentation; accept calls SIP-03; CUST-INT-01 interactions (suite →382) |
| 2026-06-12 | #21 discounts (SIP-03 / DIS-OP-01) | SIP-03 assignment lifecycle (status machine, EM-CFG-04 approval, validity, history, cancel, effective-query); DIS-OP-01 compute honours status + window + stacking groups (suite →389) |
| 2026-06-12 | #28 field audits (FA-01/02/03) | campaign→task→expected→observation→discrepancy model; expected-vs-observed comparison → typed discrepancies → Drools severity+route → EM-CFG-04-gated OSR corrections; idempotent tasks/observations (suite →394) |
| 2026-06-12 | #50 USSD (FE-CH-USSD-01) | config-driven menus (ussd_menu_definition per operator+language); stateful menu engine dispatching to owning module reads/writes; normalized gateway endpoint; request-log trace; en/sw (suite →401) |
| 2026-06-12 | #40 RBAC (EM-CFG-03) | added user scopes + withinScope enforcement, role/permission metadata, frontend-action visibility matrix + per-user navigation, scopes in effective-access, RBAC events — Spatie stays the engine (suite →406) |
| 2026-06-12 | #1 sales (SALES-01) | full pre-order pipeline (territory, rich lead lifecycle, assignment history, activities, package interest, conversion→FUL-02, immutable attribution events); duplicate detection; daily-work (suite →412) |
| 2026-06-12 | #3 customer 360 | resilient one-round-trip `/overview` composition with per-panel isolation (closes the CROSS-00 §12 partial-failure gap); Show.vue rewired (suite →415) |
| 2026-06-12 | i18n sweep | wired `useI18n().t()` across all 27 pages (was 2/27) + script-side toast/error fallbacks; studio catalog → fallback dict → English key; English byte-identical (no regression, 415 pass); fixed `v-for="t in"` shadowing; locale number/date (`money()`/`dateFmt()`) noted as separate follow-up |

**Next up (suggested order): #35 catalog setup, #46 reporting, #47/#27/#31/#42 UI surfaces.**
