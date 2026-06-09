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
- Command ledger, broadcast/dispatch, desired/observed, reconcile + force-sync — ✅
- Open (H→M): full §9 status model (`RECEIVED/DISPATCHING/ACCEPTED/…` vs current PENDING/SENT/CONFIRMED);
  async `ASYNC_ACCEPTED` poll flow; `provisioning_command_attempt` per-attempt ledger;
  force-sync **approval** model (`provisioning_force_sync_request` + EM-CFG-04). ⚠️

## Billing money path (BIL-01/02/04/05) — audited
- Invoicing (assembler + gap-free legal number), payments (allocate→PAID, surplus→credit),
  dunning ladder (rules.billing.dunning → SUB-WF ops), billing-intent bridge — ✅
- Open (M): automatic cycle billing (rated_event→invoice/wallet consumer is unwired);
  data/SMS rating uses code constants instead of a usage-tariff catalog; payment reversal;
  account-credit auto-draw. ⚠️

## Work Order (WO-01-FRAMEWORK) — audited 🔁
- Ticket source link (`source_type`/`source_ref`; Ticketing creates + waits for finalize) — ✅
- **Reassign + `wo_assignment_history`** (§1.7) — ✅ 🔁 (was ❌)
- **Structured notes + `wo_note_kind_registry`** schema validation (§1.3/§4.3) — ✅ 🔁 (was ⚠️ table-only)
- **2-step finalize** `IN_PROGRESS→FINALIZATION_PENDING→COMPLETED` + config-driven
  **finalize checklist** (`wo_finalization_requirements`, §3/§4.4) — ✅ 🔁 (was ❌; terminal renamed FINALIZED→COMPLETED)
- Open (M/L): attachments (`wo_attachment` + per-category min counts in the checklist);
  master/sub linkage (`master_wo_id`/`link_type`) actively used; SLA timestamp capture;
  skills-filtered auto-assign worker. ⚠️

## OSR — Stock & Equipment (OSR-01 / OSR-INSTANCE-01 / OSR-RMA-01) — audited
- Equipment vs material distinction (`equipment_sku.is_serialized` + `ownership_semantics`
  RETURNABLE/CONSUMABLE/RENTED; serialized→instance registry, non-serialized→quantity) — ✅
- Serialized instance lifecycle + RMA/EQP swap flow — ✅
- Open (**H**): OSR-01 stock chain is a subset — `StockService` exposes only `move()`.
  Missing the **reservation lifecycle** (reserve-on-WO-assign → consume-on-finalize →
  release-on-cancel), **stock availability** API, **cycle counts/adjustments**, and the
  `stock_reason_code` catalog as first-class. The **WO→install-movement consumption**
  (materials deducted from the van on a job) is not wired. ❌

## Ticketing (TCK-01) — not yet fully audited
WO creation + link + wait-for-finalize is implemented (`TicketService`, `AsrService`).
SLA/ASR policy seeded. A section-by-section DD diff has not been run. ⚠️ (unknown)

## Catalog / SIP / PLM-CFG — partially audited
Service/package/version, tax, discount, wallet catalog present. `package_service`
composition exists. Per-service wallet routing consumed at charge time is NOT wired
(catalog carries `default_wallet_ref`; billing doesn't read it per-line yet). ⚠️

## Foundation / Auth (EM-CFG-03 / FOUNDATION_AUTH) — known divergence
Local Sanctum + spatie/laravel-permission instead of Keycloak/OIDC. Acknowledged top
divergence; documented in CODE_TOUR.md. ⚠️ (by decision)

## Not yet audited (no DD diff run)
Ilm/CVM, Workforce, Reporting, ItOps, Rbac admin, Fulfillment order-capture,
Notification, field-audit policy depth.

---

## Fixed this pass (🔁)
1. Wallet catalog + multi-wallet ledger + per-service routing (PLM-CFG-03).
2. Prepaid billing-intent settlement from wallet (BIL-01).
3. Per-target provisioning adapter resolution (PROV-INT-01 §10.2).
4. WO-01 framework: reassign + assignment history, structured notes + kind registry +
   schema validation, 2-step finalize + config-driven checklist (terminal → COMPLETED).

## Remaining gaps, prioritized
1. **OSR-01 stock reservation + WO→install consumption** (H) — materials/equipment
   reserved on WO assign, consumed on finalize; availability + cycle counts.
2. **Provisioning §9 status model + async + force-sync approval** (H→M).
3. **Automatic cycle billing** (rated_event/recurring → invoice or wallet) (M).
4. **WO attachments + checklist attachment requirements** (M).
5. **Per-service wallet routing consumed at charge time** (M).
6. **Usage-tariff catalog for data/SMS rating** (M).
7. Full DD diff for Ticketing, Catalog depth, Ilm/CVM, Fulfillment (unknown).
