# SOPHIX BSS — Platform Extensibility Review vs. Wananchi AS-IS Confluence

**Reviewed:** 2026-06-20 · **Branch:** `claude/blissful-cori-m20e8c`
**Requirements:** Confluence `APPFREESN` →
[*Wananchi BSS/OSS — AS-IS understanding*](https://axiangroup.atlassian.net/wiki/spaces/APPFREESN/pages/2176778241/)
(executive overview + use-case feature catalog + module zooms + flows + deep dives).
**Subject:** this repository — the **generic SOPHIX V3 BSS platform** (`Modules/*`, `app/Foundation`).

## 0. Review criterion (realigned)

SOPHIX is a **generic, multi-operator BSS platform**. The goal is not to ship
Wananchi-Kenya behaviour in code, but to ensure the platform is **configurable and
extensible** so that Wananchi's specific flows, data and processes can be added **at
deployment time without changing platform code**. Each Confluence requirement is therefore
classified by *how* it is satisfied, not merely *whether* a Wananchi-specific behaviour exists:

| Class | Meaning | Verdict |
| --- | --- | --- |
| **🔌 Connector** | External node (NMS/OLT/CMTS/Verimatrix/VoipSwitch, AutoSend, M-Pesa rails, KRA eTIMS, DWH). **Not part of the BSS.** Replaced by a stub behind a swappable seam; integrated during country deployment. | ✅ Correct by design — *not a platform gap* |
| **⚙️ Configurable today** | Platform already exposes the seam (config/env, data catalog, rules decision table, workflow flow, adapter registry). Wananchi behaviour = **configuration at deployment, no code**. | ✅ On-platform — *deploy-time config* |
| **🧩 Extensibility gap** | Platform currently **hardcodes** the behaviour; the Wananchi variant cannot be expressed without editing platform code. To meet the "no code change" goal, a **generic seam** must be added (a strategy, a catalog, a rule hook, a flow step). The fix is a generic platform capability, never a Wananchi fork. | ⚠️ **Action item** |

The platform's extensibility seams are real and broad, which makes this judgement crisp:

- **Drivers** (env, zero code): `event_bus`, `workflow_driver`, `rules_driver`,
  `provisioning_driver`, `tax_driver`, `sms_driver`, `auth` (Sanctum↔Keycloak) — `config/sophix.php`.
- **Adapter registries** (class + one config entry): notification channels
  (`notification.adapter_implementations`), ICN channels, tax signers
  (`tax.signer_implementations`), provisioning per-target adapters (`ProvisioningAdapterRegistry`).
- **Workflow** (`Modules/Workflow/Engine/TaskRegistry`): *"new step = register a handler
  (code); **new flow = compose registered topics (config)**"* — flows are `process_definition`
  rows authored in the Studio.
- **Rules** (`Modules/Rules` + Rules Studio): decision tables as data, operator-overridable,
  wired into live gateways (e.g. `activation.eligibility`).
- **Data catalogs** (runtime, operator-scoped): RBAC, SLA, dunning programs, sub-status,
  HomePass status, account flags (NPD), job types, tax rules/groups, packages/bundles/voice
  tariffs, discount catalog/assignment/campaigns.

---

## 1. Executive verdict

**The platform is well-positioned against its own goal.** The revenue-critical core
(CAS/KYC, subscription MACD, invoice + fiscal signing, payment application/allocation/dunning,
WO lifecycle, OSR stock/equipment/RMA) is implemented and, crucially, **most operator
specifics are already data/rules/flow-driven** — Wananchi can be stood up largely by
configuration. The external connectors the review previously flagged are **correctly stubbed**
and are deployment-integration tasks, not platform gaps.

That leaves a focused set of **genuine extensibility gaps** — places where a Wananchi rule is
*baked into code* and so cannot be configured. These are the real backlog, and every one is
fixable as a **generic** seam that benefits all operators:

1. **Proration is hardcoded** (`ChargeComputeService::recurringFee` — actual÷nominal days).
   Wananchi's `paid ÷ (30 × BILL_FREQUENCY)` fixed-30-day rule cannot be configured. →
   *pluggable/rules-selectable proration strategy.*
2. **Bill-cycle anchoring is hardcoded.** No 28 rotating anchor-day cycles / Day-25 cohort
   scheduler; `CycleCloseService` scans `current_cycle_end <= now`. → *cycle-anchor + cohort
   schedule catalog.*
3. **Account lifecycle status is a hardcoded enum** (`CustomerAccount` ACTIVE/INACTIVE; no
   transition graph). The 5-state machine + *Churn→TERMINATED manual-only* can't be
   configured. → *catalog-driven status + allowed-transition graph (the sub-status catalog
   already proves the pattern).*
4. **Discounts never reach the invoice.** The discount catalog/engine is data-driven, but
   `ChargeComputeService` has **no discount stage**. → *wire a generic discount-compute stage
   into the charge pipeline (then discounts are pure config).*
5. **Tax-phase policy is hardcoded.** "No tax on usage", "ADJ amount is tax-inclusive",
   "discount before/after tax" aren't expressible via tax config. → *tax-applicability rule
   hook per category/phase.*
6. **Service characteristics aren't typed/configurable.** IPTV bouquets, bandwidth profiles,
   data caps live only as an untyped `service.network_profile_shape` JSON. → *generic typed
   service-characteristic schema (TMF-style CharacteristicSpec) so new service shapes are config.*
7. **Dual-wallet run + invoice grouping is partial.** Per-charge wallet routing exists; the
   "run once per wallet, one-or-two invoices" policy isn't an end-to-end config knob. →
   *per-wallet run + grouping policy (grouping dimension partly exists in `InvoiceService`).*
8. **No scheduled OPEN→OVERDUE status ager** (`Invoice::OVERDUE` defined, never set). →
   *generic status-aging job.*
9. **Generic utilities missing** that the platform should own regardless of operator:
   persisted **entity change-history** projection (HomePass/others emit events but keep no
   queryable history), **bulk-edit/clone** admin utility (Homes Modification Utility shape),
   **search index** extension for account-number and equipment MAC/serial keys, and WO
   **geolocation fields + technician-performance metric**.

Notably, several big-looking items from the first pass are **not** code gaps under this lens:
new flows such as **package-assignment WO** and the **HPRFS construction-request** lifecycle
are **authorable as workflow config** *provided the toolbox exposes the needed step topics* —
where a step is missing, the fix is one generic handler, not a Wananchi-specific flow.

---

## 2. Classification of every reviewed area

### 🔌 Connectors — correctly stubbed, integrated at deployment (not platform gaps)

| Area | Seam in place | Deployment task |
| --- | --- | --- |
| NMS / OLT-ONT (Huawei NCE, FiberHome UNM2000) | `ProvisioningAdapter` + per-target `ProvisioningAdapterRegistry`; `provisioning_driver` | Write/enable vendor adapter; map targets |
| CMTS / modem (Casa via Clearcable NOMS) | same per-target seam | Vendor adapter |
| TV (Verimatrix), Phone (VoipSwitch), RADIUS/AAA | same per-target seam (RADIUS ⚪ "not used in KE") | Vendor adapter at deployment / future AAA |
| Invoice delivery (AutoSend) + SMS/Email | `notification.adapter_implementations` registry + `sms_driver` | Add provider adapter + `channel_operator_config` |
| Outbound M-Pesa (STK/paybill initiation) | inbound callback done; outbound = provider adapter | Add M-Pesa initiation adapter |
| KRA eTIMS fiscal signing | `tax.signer_implementations` registry (`StubTaxSigner`) | Add live KRA signer + `tax_operator_config` |
| Data Warehouse / ETL export | event outbox + `ReportExportService` (CSV) | Wire DWH sink/ETL at deployment |

These are **swappable behind existing seams**; standing up a real one is configuration + an
adapter class, never a platform redesign. ✅

### ⚙️ Configurable today — Wananchi = deploy-time config, no code

- **Operator/country/currency/timezone**, driver selection — `config/sophix.php` + env.
- **Workflow flows** composed from existing topics (Studio `process_definition`): subscription
  MACD set, WO install/support/shifting, OSR swap/RMA/EQP/EQR, dunning-driven actions.
- **Rules** (decision tables, operator-overridable): activation eligibility, WO site-visit &
  resolution gates, ASR routing, CVM offers, dunning escalation policy, subscription op gates.
- **Data catalogs:** RBAC roles/permissions, SLA, **dunning programs** (grace/levels/actions),
  **sub-status catalog**, **HomePass status catalog**, **account-flag catalog incl. NPD**, **job-type
  catalog** (KE codes), **tax rules/groups/rates** (incl. multi-tax), **packages/versions/
  lifecycle**, **service bundles** (single/double/triple-play), **voice tariffs**, **discount
  catalog/assignment/campaigns/stacking**, contractor/region/skill mapping.
- **Notification** channels, providers, retry windows, regulatory send windows — config + registry.
- **Approvals** (EM-CFG-04): thresholds + role routing as data.
- **Seed data is operator data, not platform code:** the current `WIK`/Senegal POC fixtures are
  re-seeded for Wananchi KE — a configuration task, not a gap.

### 🧩 Extensibility gaps — add a generic seam (the action backlog)

| # | Gap (hardcoded today) | Evidence | Generic fix (no Wananchi fork) | Effort |
| --- | --- | --- | --- | --- |
| 1 | Proration formula | `ChargeComputeService::recurringFee` (actual÷nominal days) | Pluggable proration strategy selected by config/rules (`30×BILL_FREQUENCY` as one strategy) | S–M |
| 2 | Bill-cycle anchoring & cohort run | `CycleCloseService` scans `current_cycle_end<=now`; no anchor catalog | Cycle-anchor + cohort-schedule catalog (1–28) + Day-N pre-bill offset | M |
| 3 | Account lifecycle status machine | `CustomerAccount` ACTIVE/INACTIVE consts; `AccountService::update` no transition graph | Catalog-driven main-status + allowed-transition graph + manual-only guards (mirror sub-status catalog pattern) | M |
| 4 | Discount not applied at billing | `ChargeComputeService` has no discount step | Generic discount-compute stage in the charge pipeline (engine already data-driven) | S–M |
| 5 | Tax-phase policy | per-line tax always applied; ADJ `tax_amount=0`; no usage suppression | Tax-applicability rule hook (category/phase: usage-exempt, ADJ-inclusive, discount before/after) | M |
| 6 | Typed service characteristics | only untyped `service.network_profile_shape` JSON | Generic CharacteristicSpec schema → IPTV bouquets / bandwidth profiles / data caps become config | M–L |
| 7 | Dual-wallet run + invoice grouping | per-charge wallet routing only; run/grouping not a policy | Per-wallet run + invoice-grouping policy (extend `InvoiceService` grouping dimension) | M |
| 8 | OPEN→OVERDUE ageing | `Invoice::OVERDUE` never set | Generic scheduled status-ager | S |
| 9 | Generic utilities | events but no history table; no bulk-edit/clone; search keys; WO geo/perf | Change-history projection; bulk-ops admin endpoint; search-index extension; WO geo fields + perf metric | S each |

All nine are **platform capabilities** — once added, Wananchi (and every other operator) is
served by configuration. None requires a Wananchi-specific code branch.

---

## 3. Per-domain notes (re-tagged)

Concise restatement; full `file:symbol` evidence is in the domain analyses behind this review.

- **Customer Management (ILM/CAS).** ⚙️ creation, 360 overview, KYC chain, NPD flag, MACD,
  CVM segmentation, sub-status/preferences are config/data — strong. 🧩 main-status machine
  (#3), search keys (#9), unified interaction timeline (projection — #9). Package-assignment
  WO is ⚙️ (author the flow) given the WO-create topic exists.
- **Billing.** ⚙️ tax catalog, adjustments, signing framework, dunning programs, bundles.
  🧩 proration (#1), cycle anchoring (#2), discount-at-billing (#4), tax-phase (#5),
  dual-wallet run (#7). 🔌 AutoSend delivery, KRA signer.
- **Invoices.** ⚙️ generation, tax, adjustment, paid lifecycle. 🧩 OVERDUE ageing (#8),
  single-invoice cancel endpoint (#9). 🔌 commercial-invoice delivery/download channel.
- **Payments & Collections.** ⚙️ allocation policies, dunning/delinquency, reversal,
  overpayment/credit. 🧩 reconciliation *engine* + collection-workflow/notes + refund
  *orchestration* (the human/recovery layer is platform; build as flows + a refund op). 🔌
  the provider statement feed and outbound refund rail. ⚪ auto-pay/agent/deposit/write-off.
- **Packages & Services + Discounts.** ⚙️ package/version/lifecycle, bundles, voice tariffs,
  discount engine/stacking/governance. 🧩 typed characteristics for IPTV/bandwidth/data-caps
  (#6), loyalty-ladder as a rule/program, discount validity dims (cycle-count/event), pricing
  beyond single recurring price. 🔌 catalog→NMS push (the 3/4-place sync is connector + recon).
- **Work Order & Field Ops.** ⚙️ lifecycle, job-type catalog, skills auto-assign, notes,
  finalize/checklist, escalation/warranty, shifting/support flows, offline PWA. 🧩 geo fields +
  routes + technician-performance metric (#9), per-job-type SLA via rules, PWA photo upload
  wiring, inventory-allocation persisted on WO.
- **Provisioning.** ⚙️/✅ command ledger, desired/observed **reconciliation**, NOC force-sync,
  async poll, activation gating — the cross-cutting framework is done. 🔌 **all vendor planes**
  + service-specific semantics (ONT/DOCSIS/entitlement/SIP/IP/bandwidth-map) at deployment. 🧩
  typed `desired_state` contract + Headend/Hub/Controller routing model + retry executor are
  generic refinements for when real adapters land.
- **HPRFS / Integration / Equipment.** ⚙️ HomePass + region + node + bulk import, OSR
  stock/instances/procurement/audit/RMA, event bus, file store, tax-signing framework. 🧩
  construction-request lifecycle (author as a flow; add any missing handler topics),
  Homes Modification Utility bulk-edit/clone (#9), persisted change-history (#9), typed
  equipment provisioning-anchor fields. 🔌 onboarding-app / field-ops-app ingress APIs, DWH,
  BH↔NMS equipment reconciliation feed. **Naming:** Confluence "Invoice Signing" =
  *contract e-Signature* (DocuSign-style) — a different capability from the built KRA fiscal
  signing; clarify which Wananchi means (e-Sign provider would be a 🔌 connector).

---

## 4. Recommendation

Treat the **🔌 connectors** as the deployment workstream (adapters behind existing seams) and
the **⚙️ configurable** surface as the Wananchi configuration workstream (catalogs, rules,
flows, seed data). Focus platform engineering on the **nine 🧩 extensibility gaps** in §2 —
each makes a currently-hardcoded behaviour configurable, closing the "no code change for a
country deployment" goal without forking the platform. Items #1–#4 (proration, cycle anchor,
account-status machine, discount-at-billing) are the highest-leverage because they sit on the
revenue path and are pure Wananchi-blocking-by-hardcode today.

*Method & caveats:* requirements read from the live Confluence space (~150 feature pages, 14
deep dives, module zooms, flows); codebase inspected module-by-module. Ratings are
feature-level coverage with code citations, not a line-by-line audit; embedded diagrams were
not OCR'd. Some Confluence rule values remain open (⚪) pending Wananchi confirmation.
