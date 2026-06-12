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
| 1 | Lead → customer conversion (sales) | SALES-01 | `app/Http/Controllers/LeadController` (qualify/convert/lose) | LeadConverted | `Wave3SalesUssdTest` (lead→conversion), `CustomerApiTest` | ⬜ |
| 2 | New customer sale to activation | FUL-02 order capture | `Modules/Fulfillment/Services/OrderCaptureService::capture` → seeded `ful-order-capture` process; 6 handlers in `Modules/Fulfillment/app/Workflow`; KYC gate + install-WO message catches | OrderCaptured, CustomerKycApproved, WorkOrderFinalized, SubscriptionActivated | `FulfillmentJourneyTest` (5 journeys incl. KYC park/resume); `DemoJourneySeeder` end-to-end | ✅ |
| 3 | Customer 360 (composition screen) | read-only composition | `resources/js/Pages/Ilm/Customers/Show.vue` reading ILM/SUB/BIL/TCK APIs per panel | consumes module events indirectly | UI smoke via route tests | ⬜ |
| 4 | Customer/account/KYC master (ILM) | ILM-CFG-01 | `AccountService` (catalog-driven sub-status: derives main status, enforces requires_approval R-ILM-S-2, carries affects_provisioning), `CustomerService` (KYC state machine + config-driven approval authority per level R-ILM-K-3), flag system (Drools catalog) | CustomerKycApproved, CustomerAccountStatusChanged, flag events | `AccountFlagTest`, `CustomerApiTest`, `CvmFlagEvaluatorTest` | ✅ |
| 5 | HomePass / serviceability (RLM) | RLM-CFG-01 | `Modules/Rlm` HomePassController + serviceability checks consumed by FUL/SUB | — | covered via journey tests | ⬜ |

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
| 18 | Dunning ladder → suspension | BIL-04 | `DunningService` + decision table → SUB-WF-SUSPEND-NP | DunningStageAdvanced, SubscriptionSuspendedForNonPayment | `DunningTest` | ⬜ |
| 19 | Tax invoice & fiscalisation | BIL-02-TAX-01 | `TaxService` + `TaxGateway` driver (stub) | TaxInvoiceIssued | `TaxComputeTest` | ⬜ |
| 20 | **Adjustments → credit/debit notes** (added) | BIL-02-ADJ-01 / BIL-01-CN-01 | `AdjustmentService` (scopes, limits, rules-routed approval pinned per proposal) → `InvoiceService::issueNote` → `NoteApplicationService` (ledger) | AdjustmentProposed/Approved, Credit/DebitNoteIssued/Applied, DebitNoteApplicationFailed | `AdjustmentTest` (10) | ✅ |
| 21 | Discounts at billing time | SIP-03 / DIS-OP-01 | DiscountAssignment + compute at billing | — | `DiscountComputeTest` | ⬜ |

### 1.4 Field operations & inventory

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 22 | Work order lifecycle (framework) | WO-01 | `WorkOrderService` (fixed state machine, reassignment, structured notes, 2-step finalize + checklist) | WorkOrderAssigned/Finalized/Cancelled | `WorkOrderFrameworkTest` | ✅ |
| 23 | Provisioning dispatch / retry / reconciliation / force-sync | PROV-INT-01 | `ProvisioningService` + `ProvisioningAdapterRegistry` (per-target adapters), attempt ledger, `ReconciliationService` approval flow | ProvisioningCommand* | `AdapterRoutingTest`, `ReconciliationTest` | ✅ |
| 24 | Stock chain + reservations | OSR-01 | `Modules/Osr/Services/StockService` (reserve→consume on WO finalize; R-OSR-SC-8 no-negative, SC-9 approver for write-off/cycle-count, SC-4 two-tier transfer guard, SC-7 reservation expiry sweeper) | StockMoved/Reserved/Consumed/Released/Expired | `StockReservationTest`, `StockRulesTest` | ✅ |
| 25 | Equipment instances, swap, RMA | OSR-INSTANCE / OSR-RMA-01 | `EquipmentInstanceService` state machine + swap/RMA flows; INST-2 serialized-only, INST-5 event_sequence, INST-7 contractor_id on recovery (the routing fix), INST-9 terminal active=false; named lifecycle events | Equipment* (Recovered/Bound/Unbound/Decommissioned) | `OsrApiTest`, `SwapRequestTest` | ✅ |
| 26 | Procurement, receipt, audit, write-off | OSR-02/05 | `ProcurementService` (PO → approve → receive → OSR-01 movement + OSR-INSTANCE register for serialized, R-OSR-02-08), `InventoryAuditService` (count→variance→idempotent reconcile, R-OSR-05-09) | StockMoved | `ProcurementAuditTest` | ✅ |
| 27 | Warehouse backoffice (added) | UI over OSR | `resources/js/Pages/Osr/Warehouse.vue` | — | UI route test | ⬜ |
| 28 | Field audits | FA-01/02/03 | audit_type-driven process + FieldAuditPolicySeeder | Audit* | `FieldAuditTest` | ⬜ |
| 29 | Contractor / staff / team registry | EM-02 | `Modules/Workforce`: registry + capacity hot path — `ContractorAvailabilityService` (region-scope/skill/slot resolution R-EM-CS-1..6, atomic slot commitment R-EM-CS-6/7) | ContractorSlotCommitment* | `WorkforceApiTest` | ✅ |

### 1.5 Care, assurance & engagement

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 30 | Ticket lifecycle + SLA + ticket→WO | TCK-01 (+ ASR satellites) | `TicketService` (TCK-2 link rule, category catalog gating, one-active-WO, TCK-6 `ticket_link` on WO create, canonical `WAITING_WORK_ORDER`, WO finalized→RESOLVED/UNDER_REVIEW + WO cancelled→review loop, first-response SLA) | TicketCreated/TicketWorkOrderCreated/Reopened | `TicketApiTest`, `AsrTest` | ✅ |
| 31 | Ticketing cockpit UI (added) | UI over TCK | `resources/js/Pages/Tickets/Index.vue` (queues, SLA, timeline, actions) | — | — | ⬜ |
| 32 | Notifications + templates studio | NOT-01 / ICN-01 | `NotificationService` + `TemplateService` (per-channel/locale templates); `Notification/Studio.vue` | NotificationSent | `TemplateStudioTest` | ⬜ |
| 33 | CVM retention | EM-03 | `CvmService` (rule-based case selection, action queues) | CvmCase* | `CvmTest` | ⬜ |
| 34 | Campaigns designer + bundles launch | SIP-04 / SIP-05 | `BundleService` (launch-check pipeline, lifecycle, availability, migration-preview→SUB), `CampaignService` (eligibility, unique participation→SIP-03 assignment, validate-gated activation R-SIP-CAMP-02/03/04/05); `Catalog/Commercial.vue` | Bundle*/Campaign* | `BundleAndCampaignTest` (7) | ✅ |
| 35 | Catalog setup (service/package/tax/wallet/tariff) | PLM/SIP/BIL config | `Modules/Catalog` models + seeders; cache-aside consumers | CatalogChanged (evicts) | `CacheFoundationTest`, catalog tests | ⬜ |

### 1.6 Platform & cross-cutting

| # | Feature | Command owner (DD) | Implementation entry points | Events | E2E proof (tests) | Tour |
|---|---|---|---|---|---|---|
| 36 | Workflow engine (definitions as data, external tasks, message catches) | FOUNDATION_CAMUNDA (local engine) | `Modules/Workflow`: ProcessDefinition graphs, TaskRegistry, `sophix:workflow:work`, correlateMessage | — | `WorkflowEngineTest` | ✅ |
| 37 | Rules engine (decision tables, operator override) | FOUNDATION_DROOLS (local engine) | `DataDrivenRuleEngine` (FIRST/COLLECT, fallbacks, operator-scoped tables) | — | `DecisionTableTest`; `AdjustmentTest` routing | ✅ |
| 38 | Outbox/inbox event bus | FOUNDATION_KAFKA (local) | `EventBus` → outbox_events → `sophix:outbox:dispatch` → listeners; correlation ids | all | event tests throughout | ✅ |
| 39 | Cache foundation | FOUNDATION_CACHE | `SophixCache` (cache-aside, TTLs, event eviction, admin invalidate/stats) | — | `CacheFoundationTest` | ✅ |
| 40 | RBAC + admin portal + change audit | EM-CFG-03 | spatie + `RbacController` + `rbac_change_audit`; `Rbac/Admin.vue` | — | `RbacApiTest` | ⬜ |
| 41 | Approval workflow catalog | EM-CFG-04 | `ApprovalController` + definitions; consumed by force-sync, bundles | — | `FileAndApprovalTest`, `ReconciliationTest` | ⬜ |
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
| 50 | USSD channel adapter | FE-CH-USSD-01 | `UssdController` menu/session over existing APIs | — | `Wave3SalesUssdTest` | ⬜ |

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
| Customer 360 partial failure | per-panel client fetches degrade independently (no test yet) | ⬜ gap |
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

**Next up (suggested order): #18 dunning,
then #30 ticketing/SLA, #24–#26 OSR, #34 campaigns/bundles, #4 ILM, #50/#47 channels.**
