# SOPHIX BSS — Platform Extensibility Review vs. Wananchi AS-IS Confluence

**Reviewed:** 2026-06-20 · **Branch:** `claude/blissful-cori-m20e8c`
**Requirements:** Confluence `APPFREESN` →
[*Wananchi BSS/OSS — AS-IS understanding*](https://axiangroup.atlassian.net/wiki/spaces/APPFREESN/pages/2176778241/).
**Subject:** the **generic SOPHIX V3 BSS platform** (`Modules/*`, `app/Foundation`).

> **Correction note.** An earlier draft of this review over-flagged proration, tax, the
> account-status model and discounts as gaps. Those findings were wrong — they came from
> feature-level summaries that did not trace the platform's config/rules seams. After reading
> the actual services (`TaxComputeService`, `ChargeComputeService`, `Subscription::cyclePeriod`,
> `DiscountComputeService`, `InvoiceService`, `CustomerAccount`), each is **configurable today**.
> This version reflects the verified code.

## 0. Review criterion

SOPHIX is a **generic, multi-operator BSS platform**. The goal is not to ship Wananchi-Kenya
behaviour in code, but to ensure Wananchi's flows, data and processes can be added **at
deployment time without changing platform code**. Each requirement is therefore classified by
*how* it is satisfied:

| Class | Meaning | Verdict |
| --- | --- | --- |
| **🔌 Connector** | External node (NMS/OLT/CMTS/Verimatrix/VoipSwitch, AutoSend, M-Pesa rails, KRA eTIMS, DWH). Not part of the BSS; stubbed behind a swappable seam; integrated at country deployment. | ✅ correct by design |
| **⚙️ Configurable today** | Platform exposes the seam (config/env, data catalog, **rules decision table**, workflow flow, adapter registry). Wananchi = configuration, **no code**. | ✅ on-platform |
| **🧩 Extensibility gap** | Platform **hardcodes** the behaviour; the variant needs a generic seam added. | ⚠️ action item |

## 1. Executive verdict

**The platform meets its configurability goal across the revenue-critical path.** The four
behaviours most likely to be "Wananchi-specific" are all driven by configuration, not code:

| Wananchi specific | How it's configured (verified) | Evidence |
| --- | --- | --- |
| Tax on service / no tax on usage / exemptions | `rules.tax-applicability` decision table resolves the tax group per `taxableKind`/`customerCategory`; `NONE` = exempt; product `default_tax_group_ref` fallback; cascading `BASE`/`BASE_PLUS_PRIOR` | `Catalog/Services/TaxComputeService::compute` |
| Proration `paid ÷ (30 × BILL_FREQUENCY)` | set `cycle_period_days` = 30/180/360 → `recurringFee` prorates `price × actualDays/nominalDays`, identical to the formula | `Subscription::cyclePeriod`, `Billing/Services/ChargeComputeService::recurringFee` |
| 28 anniversary cycles + Day-25 pro forma | per-subscription `current_cycle_start/end` anchor (data); cohorts emerge per anchor day; pro forma fires 5 days before cycle end (= Day 25 for a 30-day cycle) | `Billing/Services/CycleCloseService`, `ProFormaService` (`PRE_CYCLE_WINDOW_DAYS = 5`) |
| Dual-wallet (Internet/TV vs Voice) → 1 or 2 invoices | `invoice_grouping_config.grouping_dimension = WALLET`; per-charge wallet routing; `Charge::groupKey('WALLET')` splits invoices | `Billing/Services/InvoiceService`, `Charge`, `ChargeComputeService::usageWalletRef` |
| Account lifecycle states & sub-statuses | `customer_account.status` is a free string; `sub_status` is the data-driven, operator-scoped `CustomerSubStatusCatalog` (approval roles, provisioning effects) | `Ilm/Models/CustomerAccount`, `CustomerSubStatusCatalog` |
| Discounts / promotions, credited to wallet or invoice | full discount + promo engine (stacking, campaigns); grants delivered via the adjustment/credit-note path with `target_kind ∈ {INVOICE, WALLET, CREDIT_BALANCE}` | `Catalog/Services/DiscountComputeService`, `Billing` adjustment tables |

Plus the broad platform seams: env-selected **drivers** (event bus, workflow, rules,
provisioning, tax, sms, auth), **adapter registries** (notification/ICN channels, tax signers,
per-target provisioning adapters), the **workflow toolbox** (`TaskRegistry`: *new flow = compose
registered topics as config*), the **rules engine + Studio** (operator-overridable decision
tables), and **runtime catalogs** (RBAC, SLA, dunning programs, job types, tax rules,
packages/bundles/voice tariffs, discounts, account flags incl. NPD).

**Net:** Wananchi can be stood up largely by **configuration + connector integration**. The
genuine platform backlog is small.

## 2. What actually remains

### 🔌 Connectors — integrate at deployment (not platform gaps)

NMS/OLT-ONT (Huawei NCE, FiberHome UNM2000), CMTS/modem (Casa via Clearcable NOMS), TV
(Verimatrix), Phone (VoipSwitch); invoice delivery (AutoSend) + SMS/Email; outbound M-Pesa
(STK/paybill) initiation; KRA eTIMS live signer; DWH/ETL export. **All sit behind existing
swappable seams** (`provisioning_driver` + `ProvisioningAdapterRegistry`,
`notification.adapter_implementations`, `tax.signer_implementations`, event outbox →
`ReportExportService`). Standing one up = an adapter class + config row at deployment. ✅

### 🧩 Genuine extensibility / additive gaps (small, generic, non-blocking)

| Item | What's missing | Generic fix | Priority |
| --- | --- | --- | --- |
| Discount auto-application at cycle close | Engine exists + grants credit wallet/invoice, but `ChargeComputeService` does not auto-insert a discount line during the recurring run | If Wananchi wants discount-as-invoice-line (vs wallet credit): add a discount-compute stage to the charge pipeline — config thereafter | Optional (depends on model) |
| OPEN→OVERDUE status ager | `Invoice::OVERDUE` defined but never set; dunning works off `due_date < now` directly (operationally fine) | Generic scheduled status-ager | Low (cosmetic) |
| Typed service characteristics | IPTV bouquets / bandwidth profiles / data caps held as untyped `service.network_profile_shape` JSON | Optional CharacteristicSpec schema if richer typing/validation is wanted; otherwise JSON config + connector reads it | Low–Med |
| Persisted change-history | HomePass (and some entities) emit domain events but keep no queryable history table (Package/Account already have one) | Generic change-history projection over the existing outbox | Low |
| Bulk-edit / clone admin utility | Homes Modification Utility shape (bulk "set status/region", clone address); only bulk *create* import exists | Generic bulk-ops endpoint | Low |
| Search-key extension | Customer search lacks account-number and equipment MAC/serial keys (the two most-used in KE) | Extend the search index | Low |
| WO geolocation + technician-performance | WO carries `homepass_id` only; no lat/long or per-tech finalized-WO metric | Add geo fields + a reporting metric | Low |
| ADJ/credit-note tax treatment | `issueNote` writes notes with `tax_amount = 0`; doc treats ADJ amount as tax-inclusive | Decide tax decomposition on notes (modeling choice) | Low |

None blocks a Wananchi deployment; each is a small generic enhancement, never a fork.

## 3. Per-domain (re-tagged, verified)

- **Customer Management (ILM/CAS):** ⚙️ creation, 360 overview, KYC chain, NPD flag, MACD,
  CVM segmentation, **string status + data-driven sub-status catalog**. 🧩 search keys, unified
  interaction timeline (projection), general document store — all small/additive.
- **Billing & Invoices:** ⚙️ rules-driven tax, **configurable proration** (`cycle_period_days`),
  per-subscription cycle anchor + Day-25 pro forma, **WALLET invoice grouping**, adjustments,
  KRA signing framework, dunning programs. 🧩 OVERDUE ager, optional discount-line-at-cycle-close.
  🔌 AutoSend delivery, KRA live signer.
- **Payments & Collections:** ⚙️ allocation policies, dunning/delinquency, reversal,
  overpayment/credit, callback ingest + dedupe. 🔌 provider statement feed (reconciliation
  input) + outbound refund rail. (Reconciliation/collection-workflow can be built as flows if
  Wananchi needs them beyond dunning.) ⚪ auto-pay/agent/deposit/write-off (workshop: not used).
- **Packages & Services + Discounts:** ⚙️ packages/versions/lifecycle, bundles, voice tariffs,
  **discount + promo engine** (credits wallet/invoice/credit-balance), stacking, campaigns,
  governance. 🧩 typed characteristics for IPTV/bandwidth/data-caps (optional). 🔌 catalog→NMS push.
- **Work Order & Field Ops:** ⚙️ lifecycle, KE job-type catalog, skills auto-assign, notes,
  finalize/checklist, escalation/warranty, install/support/shifting flows, offline PWA. 🧩 geo
  fields + routes + technician-performance metric, PWA photo upload, per-job-type SLA via rules.
- **Provisioning:** ⚙️/✅ command ledger, desired/observed **reconciliation**, NOC force-sync,
  async poll, activation gating — the cross-cutting framework is done. 🔌 all vendor planes +
  service-specific semantics at deployment. 🧩 typed `desired_state` contract + Headend/Hub
  routing model are refinements for when real adapters land.
- **HPRFS / Integration / Equipment:** ⚙️ HomePass + region + node + bulk import, OSR
  stock/instances/procurement/audit/RMA, event bus, file store, tax-signing framework.
  🧩 construction-request lifecycle (author as a workflow; add any missing step topic), Homes
  Modification Utility, persisted change-history. 🔌 onboarding/field-ops app ingress, DWH,
  BH↔NMS equipment reconciliation feed. **Clarify:** Confluence "Invoice Signing" =
  *contract e-Signature* (a 🔌 connector), distinct from the built KRA fiscal signing.

## 4. Recommendation

The platform is in good shape against the "configure, don't code, for a country deployment"
goal. Two workstreams carry Wananchi: (1) **connector integration** (adapters behind existing
seams) and (2) **configuration** (cycle days, tax-applicability rules, wallet grouping,
sub-status/status catalog values, dunning programs, discount/promo catalog, job types, seed
data). Reserve platform engineering for the short **🧩** list in §2 — all small, generic, and
optional. Re-seed the current `WIK`/Senegal POC fixtures for Wananchi KE as configuration data.

*Method & caveats:* requirements read from the live Confluence space; key findings re-verified
against the named service classes (not just feature summaries). Ratings are coverage-level with
code citations; embedded diagrams were not OCR'd; some Confluence rule values remain open (⚪)
pending Wananchi confirmation.
