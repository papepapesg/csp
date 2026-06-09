# DD Conformance Audit

A living register that diffs the **implementation against the DDs**, module by module,
so gaps are tracked in one place instead of discovered one at a time. Replaces the
ad-hoc "explore the code and hope it matches" loop with a spec-first diff.

Legend: ✅ conformant · ⚠️ partial (table/scaffold present, behavior incomplete) ·
❌ missing · 🔁 fixed this pass. Severity: **H**igh (framework contract / money / state),
**M**edium, **L**ow.

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
- **Automatic cycle billing** (`CycleBillingService`: unbilled rated_events settle at
  cycle close — POSTPAID→invoice, PREPAID→wallet drain) — ✅ 🔁
- Open (M): data/SMS rating uses code constants instead of a usage-tariff catalog;
  recurring package-fee generation; payment reversal; account-credit auto-draw. ⚠️

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
- Open (M/L): `ticket_link` general multi-entity links + `ticket_attachment`; gap-free
  `ticket_number`; full WAITING_*/UNDER_REVIEW status set. ⚠️

## Catalog / SIP / PLM-CFG — partially audited
Service/package/version, tax, discount, wallet catalog present. `package_service`
composition exists. DATA/SMS rating now reads the **`usage_tariff`** catalog (config,
not constants) — ✅ 🔁. Per-service wallet routing consumed at charge time is NOT wired
(catalog carries `default_wallet_ref`; billing doesn't read it per-line yet). ⚠️

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
- Order-capture journey (CAPTURE → VALIDATE → PAYMENT → SUBSCRIPTION → INSTALL →
  ACTIVATION → CANCELLATION) orchestrating SUB/WO/BIL — ✅
- **STEP-KYC activation gate** (an order cannot activate until the customer's KYC is
  APPROVED, ILM-CFG-01) — ✅ 🔁
- Open (L): explicit per-step failure rows; install-equipment reservation at capture.

## Rbac (EM-CFG-03) — audited
- Role + permission catalog (runtime CRUD), role-permission matrix, user role
  assignment, **effective-access API** — ✅ (on the local spatie stack).
- Open (by decision): the EM-CFG-03 **scope system** (operator/franchise/region/
  contractor/team/channel) + frontend-action matrix + rbac_change_audit. Part of the
  **FOUNDATION_AUTH divergence** (local Sanctum/spatie vs Keycloak + the rbac_* scope
  catalog); scope enforcement touches every endpoint — a deliberate deferral. ⚠️

## Workforce (EM-02) / Reporting (RPT) / ItOps — functional, not deep-diffed
Registry/platform modules with passing tests: Workforce = contractor/team/staff
registry; Reporting = daily-metric CSV export + reconciliation; ItOps = service
control / heartbeat / system-log. No section-by-section DD diff; no obvious behavioral
gaps surfaced. ⚠️ (low risk)

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

## Remaining gaps, prioritized
1. ~~OSR-01 stock reservation + WO→install consumption~~ — ✅ done this pass.
2. **Provisioning** force-sync approval ✅ done; remaining §9 status model + async + attempt ledger (M).
3. ~~Automatic cycle billing~~ — ✅ done (usage). Remaining: recurring package-fee generation (M).
4. ~~WO attachments + checklist attachment requirements~~ — ✅ done.
5. **Per-service wallet routing consumed at charge time** (M).
6. ~~Usage-tariff catalog for data/SMS rating~~ — ✅ done.
7. Full DD diff for Ticketing, Catalog depth, Ilm/CVM, Fulfillment (unknown).
