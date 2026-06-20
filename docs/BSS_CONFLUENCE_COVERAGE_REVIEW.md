# SOPHIX BSS — Coverage Review vs. Wananchi AS-IS Confluence

**Reviewed:** 2026-06-20 · **Branch:** `claude/blissful-cori-m20e8c`
**Source of truth (requirements):** Confluence space `APPFREESN` →
[*Wananchi BSS/OSS — AS-IS understanding (executive overview)*](https://axiangroup.atlassian.net/wiki/spaces/APPFREESN/pages/2176778241/)
and its descendants (use-case feature catalog, module zooms, flows, deep dives).
**Subject under review:** this repository (`Modules/*`, `app/Foundation`), the all-Laravel
SOPHIX V3 BSS (Waves 0–3, 17 modules, 156 feature tests).

Legend: ✅ implemented · 🟡 partial / fidelity gap · 🔴 missing · ⚪ out of scope
(the Confluence page itself records the 2026-04-2x workshop decision "not used by Wananchi").

---

## 1. Executive summary

The codebase is a **mature, well-architected BSS engine** that **meets or exceeds** the
Confluence requirements across the revenue-critical core: customer/CAS identity, KYC,
the NPD flag, the subscription MACD state machine, invoice generation + KRA-style
fiscal signing, payment application/allocation/dunning, the work-order lifecycle, the
OSR stock/equipment/RMA chain, and a faithful event-outbox / config-driven workflow /
rules-engine foundation. Several areas are **deliberately ahead** of the documented spec
(versioned dunning programs, OSR 9-state instance machine, field-audit subsystem,
campaign/discount governance).

The gaps cluster in three predictable places:

1. **External integration / network reality.** Provisioning is a faithful generic
   *broadcast + reconcile + force-sync* framework but **stub-only** — none of the five
   vendor planes (Huawei/FiberHome OLT-ONT, Casa/Clearcable CMTS-modem, Verimatrix TV,
   VoipSwitch phone) nor the service-specific semantics (IP assignment, bandwidth-profile
   mapping, entitlements, SIP credentials) are built. Invoice **delivery** (AutoSend),
   commercial-invoice **download**, outbound M-Pesa initiation, payment **reconciliation**,
   **refund disbursement**, and **DWH/ETL export** are absent.

2. **Wananchi-specific billing shape.** The **28 rotating anniversary bill-cycles**, the
   exact **`paid ÷ (30 × BILL_FREQUENCY)` proration formula**, the **Day-25 cohort
   scheduler**, the **dual-wallet (Internet/TV vs Voice) per-wallet run**, and the
   **OPEN→OVERDUE** invoice transition are only partially present or modelled differently.

3. **Catalog breadth + operational utilities.** IPTV (bouquets/premium/PPV), Internet
   bandwidth-profile & data-cap entities, the **3/4-place catalog sync & reconciliation**,
   the **HPRFS construction-request lifecycle**, the **Homes Modification Utility** (bulk
   edit/clone), persisted **change-history** views, **geolocation/technician-route**
   features, and **technician performance reporting** are missing.

A material structural finding: the **account lifecycle status model** does not match the
spec's 5-state machine (`INACTIVE/ACTIVE/SUSPENDED/CHURN/TERMINATED`). The account carries
only `ACTIVE/INACTIVE` + a sub-status catalog; SUSPENDED/TERMINATED live on the
*Subscription*, so the "**Churn → TERMINATED, manual only**" rule is not enforceable where
the spec places it. This deserves an explicit design decision before cutover.

### Coverage scorecard (indicative)

| Domain | ✅ | 🟡 | 🔴 | ⚪ | Headline |
| --- | --- | --- | --- | --- | --- |
| Customer Management (ILM/CAS) | core | lifecycle status, search keys, timeline | doc store (general), notifications-in-ILM | multi-loc, credit limit, SLA | Strong; lifecycle-status model is the key risk |
| Billing | adjustments, tax-line, signing | recurring/proration/bundle/usage | bill-cycle, **discounts**, delivery, preview | multi-currency, late fee, dispute, contract | Pipeline strong; Wananchi cycle/proration shape + discounts are the gaps |
| Invoices | IN-01/02/03/07 | IN-05/06/09 | IN-04 download, IN-08 OVERDUE transition | — | Generation/signing solid; lifecycle + delivery incomplete |
| Payments & Collections | mobile-money, allocation, dunning, reversal, overpayment | cash, reporting | **reconciliation, refunds, collection workflow/notes** | autopay, agent, deposit, write-off | Application strong; recovery + reconciliation are hard gaps |
| Packages & Services | voice tariffs, bundles, lifecycle | Internet/IPTV typing, pricing | **bandwidth profiles, data caps, IPTV bouquets/premium** | — | Voice/bundle/lifecycle strong; Internet/IPTV depth missing |
| Discounts | %, fixed, campaigns, package/customer-specific, stacking | rule predicates, validity dims | **loyalty engine** | — | Engine solid; loyalty ladder + predicate AST + cycle/event validity missing |
| Work Order & Field Ops | lifecycle, assignment, notes, photos, finalize, escalation, warranty | SLA, equipment binding, appointment, skills | **geolocation, routes, perf tracking, inventory alloc, PWA photo** | parts | Backend strong; field/geo/reporting features missing |
| Provisioning | command ledger, reconciliation, force-sync, poll | suspend/terminate (status-only), bandwidth passthrough, retry | **all 5 vendor adapters, IP assignment, notifications, SLA** | RADIUS, device-auth, auto-prov | Framework done; every real integration deferred |
| HPRFS / Integration / Equipment | home pass + region + node + bulk import; OSR stock/instances/procurement/audit; event bus, file store, tax signing | franchise/agent attribution, gateway (inbound only), fieldops API | **construction-request, HMU bulk edit, change-history, onboarding API, DWH ETL, BH↔NMS recon** | WO notes (other module) | Catalog & OSR strong; integration surfaces & HPRFS workflows thin |

---

## 2. Cross-cutting / highest-priority gaps

These touch multiple modules or block the Wananchi rollout and should be prioritised:

1. **Account lifecycle status model mismatch (design decision).** Implement the spec's
   account-level 5-state machine (or formally ratify the current account=2-state +
   subscription-state split) and enforce *Churn → TERMINATED manual-only*. — *ILM /
   Subscription*
2. **Wananchi billing cycle & proration shape.** 28 rotating anchor-day cycles, Day-25
   cohort scheduler, fixed-30-day `paid ÷ (30 × BILL_FREQUENCY)` proration, and the
   **dual-wallet (Internet/TV vs Voice) per-wallet run**. — *Billing*
3. **Discount engine in the billing path.** A discount catalog/assignment + a `DIS`
   charge stage at cycle-close (today `ChargeComputeService` has no discount step), plus
   the loyalty ladder, predicate eligibility, and cycle-count/event-bound validity. —
   *Billing / Catalog*
4. **Invoice delivery + commercial-invoice download.** AutoSend/Email-else-SMS dispatch,
   bill-image preview, and a customer/agent invoice-PDF pull. KRA *signing* is built;
   *delivery* and *download* are not. — *Billing / Notification*
5. **Payment reconciliation + refund disbursement + collection workflow.** Provider /
   statement matching with an exception queue (doc: "daily mandatory"), outbound refunds
   to the customer, and the human recovery layer (cohorts, PTP, collection notes). —
   *PaymentGateway / Billing*
6. **Provisioning vendor adapters.** Build real adapters on the existing per-target seam
   (Huawei NCE / FiberHome UNM2000 / Casa-Clearcable NOMS / Verimatrix / VoipSwitch) with
   typed service semantics (ONT registration, DOCSIS class-of-service, entitlements, SIP
   creds, IP assignment, bandwidth-profile mapping). — *Provisioning*
7. **Catalog ↔ network sync & reconciliation.** The 3/4-place catalog problem
   (BH + Supercontroller + NMS + Field-Agent app) is resolved *conceptually* by SOPHIX as
   single source, but no push/replication/cross-place reconciliation audit exists. —
   *Catalog / Provisioning*
8. **OPEN→OVERDUE invoice transition.** A scheduled job to set the OVERDUE status (dunning
   compensates operationally, but the invoice status is never set). — *Billing*

---

## 3. Per-domain detail

The full evidence tables (one row per Confluence feature, with `file:symbol` citations,
status, and gap notes) are reproduced below as captured by the domain reviews.

### 3.1 Customer Management (ILM / CAS) — `Modules/Ilm` (+ `Modules/Subscription`)

**Strong:** account creation (3-tier CAS, `account_number` unique-per-operator,
`CustomerAccount::generateAccountNumber`), 360 overview (`CustomerOverviewService::overview`),
KYC two-level enforced chain (`CustomerService::recordKycDecision`, `kyc_approval_role`),
NPD flag **independent of lifecycle** (`CustomerAccountFlag` + `AccountFlagCatalogSeeder`
`NPD`, `surfaces_attention`/`affects_dunning`), MACD via workflow
(`Subscription/OperationController` activate/pause/resume/terminate/upgrade/downgrade/
relocate/migrate), CVM segmentation (extra vs D1).

**Key gaps / fidelity:**
- Account lifecycle = `ACTIVE/INACTIVE` only (`CustomerAccount.php:25-27`) vs spec's 5
  states; SUSPENDED/TERMINATED on `Subscription`; *Churn→TERMINATED manual-only*
  unenforceable. **No state machine** (`AccountService::update` allows any catalog sub-status).
- **Search** missing the two most-used Kenya keys: **account-number** and **equipment
  (MAC/serial)**; only msisdn/name/id/email wired (`CustomerController::index`).
- **Package Assignment** does not create the mandated WO (only relocation does, via
  `CreateShiftingWoHandler`); no equipment-delta audit-WO vs contractor-WO routing.
- **Interaction history** is an ILM-local table, not the unified cross-source timeline.
- Contact rule "≥1 primary MSISDN at every update" under-enforced; service-class is
  free-text (no VIP/Platinum/Gold/STAFF enum) and overlaps with `vip`/`staff` sub-statuses;
  general (non-KYC) **document store** absent.

### 3.2 Billing — `Modules/Billing`

**Strong:** invoice generation + per-line tax + tax_summary (`InvoiceService::writeStructuredInvoice`,
`TaxComputeService`), KRA-style signing with retry/backoff and gap-free legal numbers
(`TaxSigningService`, `TaxInvoiceGenerator`), credit/debit-note adjustments with rules-engine
approval (`AdjustmentService`, `NoteApplicationService`), dual-wallet model + voice wallet,
dunning (`DunningService`).

**Key gaps / fidelity:**
- **Bill Cycle Management 🔴** — no 28 rotating anchor-day cycles, no anniversary binding,
  no join-day rounding, no admin join→cycle mapping.
- **Proration 🟡** — `ChargeComputeService::recurringFee` uses actual÷nominal *calendar* days,
  **not** the documented `paid ÷ (30 × BILL_FREQUENCY)` fixed-30-day rule; no BILL_FREQUENCY
  (30/180/360).
- **Recurring Billing 🟡** — per-subscription scanner exists (`CycleCloseService`) but no
  Day-25 anchor-day cohort scheduler; `ProFormaService` 5-day window is generic.
- **Discount Management 🔴** — no discount catalog/assignment/`DIS` txn; no discount stage
  in charge compute.
- **Invoice Delivery 🔴 / Bill Preview 🔴** — no AutoSend dispatch, no bill-image preview.
- Fidelity: ADJ written `tax_amount=0` (doc: ADJ amount is tax-*inclusive*); no-TAX-on-usage
  not enforced (usage flows through normal tax compute); in-house rating present where doc
  says Voipswitch owns rating; Wananchi CDR-format adapter not built.

### 3.3 Invoices — `Modules/Billing` (invoice surface)

**Strong:** IN-01 generation (per-wallet grouping), IN-02 tax, IN-03 adjustment
(approval-gated, immutable note), IN-07 paid (idempotent application, partial→paid,
surplus credit).

**Gaps:** **IN-04 download 🔴** (tax-invoice PDF only via `TaxInvoiceController::pdf`; no
commercial-invoice/self-care/bulk export); **IN-08 OVERDUE 🔴** — `Invoice::OVERDUE` const
defined but **never set**; no scheduled OPEN→OVERDUE job (dunning reads `due_date < now`
directly); IN-05/06/09 partial (initial status `OPEN` collapses Generated/Pending; cancel is
bulk-only → `VOID`, no single-invoice admin cancel endpoint).

### 3.4 Payments & Collections — `Modules/Billing` + `Modules/PaymentGateway`

**Strong:** mobile-money/card/bank callback ingest + dedupe + account resolution
(`GatewayCallbackService`), payment allocation policies (`PaymentService::receiveAndApply`,
FIFO/targeted/largest/smallest), partial/overpayment (`settleSurplus`, `AccountCreditBalance`),
payment reversal + bulk reversal (FINANCE_HEAD), dunning escalation
(warn→restrict→suspend→terminate, versioned `DunningProgram`).

**Hard gaps 🔴:** **Payment Reconciliation** (no statement pull/match/exception queue —
doc "daily mandatory"); **Refund Handling** (no outbound money to customer; credit balance
only applies to future invoices); **Collection Workflow + Collection Notes** (no cohorts,
PTP, agent contact-notes — `NoteApplication` is an accounting credit/debit note, a name
collision). **Cash Payments 🟡** posts via OFFLINE but lacks cash-drawer/receipt/close-out.

**Fidelity:** single-payment reversal not approval-gated (bulk is — inconsistent); NPD
modelled as a generic dunning accelerant boolean, not a queryable population; "latest-first"
allocation policy missing; multi-currency mismatch handling shallow. ⚪ Auto-pay / agent /
deposit / write-off correctly absent (workshop "not used").

### 3.5 Packages & Services + Discounts — `Modules/Catalog`

**Strong:** package creation + composition + franchise/region scoping (`CatalogService::createPackage`),
**VoIP** tariffs (`VoiceTariff*`, exceeds spec), service **bundles** single/double/triple-play
(`CommercialBundle` + components/roles/discount-rules), **package lifecycle** state machine
(`PackageLaunchService`, approval-gated), discount **stacking** (`DiscountComputeService`),
percentage/fixed/campaign/package/customer-specific discounts with governance
(`DiscountAssignmentService`).

**Gaps 🔴:** **Bandwidth profiles** (PS-10) and **data caps/FUP** (PS-11) — only an untyped
`service.network_profile_shape` JSON; **IPTV bouquets** (PS-12) and **premium/PPV packs**
(PS-13); **loyalty discount engine** (only a `LOYALTY` campaign label + points wallet);
**discount validity** has only the date dimension (no cycle-count/event-bound). **Pricing 🟡**
single recurring `price` per version (no install/upgrade fee or per-tier scale). **3/4-place
sync 🟡** SOPHIX-as-single-catalog but no NMS/Supercontroller/Field-Agent push or recon.

**Fidelity:** lifecycle name drift (`END_OF_SALE/END_OF_LIFE` vs Deprecated/Retired); two
activation paths (`CatalogService::activatePackage` bypasses launch checks vs
`PackageLaunchService::activate`); bundle discount is catalog-bound, not runtime
combination-detected; `max_redemptions` declared but not enforced in compute; discount
before/after-tax phase unmodelled.

### 3.6 Work Order & Field Operations — `Modules/WorkOrder` + `Modules/Workforce` + Field PWA

**Strong:** WO lifecycle + PENDING + history (`WorkOrderService`), KE job-type catalog
(`WoKeJobTypeCatalogSeeder`, ~50 codes), skills-aware auto-assign (`autoAssignToContractor`),
shifting disconnect/reconnect (`ShiftingFlowService`), schema-validated field notes +
photo attachments + 2-step finalize (`finalizeFirstConfirm`/`SecondConfirm`,
`enforceFinalizationChecklist`), escalation Pattern A QCS + warranty/RPT
(`CheckWarrantyHandler`), offline PWA claim/start/finalize.

**Gaps 🔴:** **geolocation** (WO has only `homepass_id`; GPS only on field-audit obs);
**technician routes** (no day-route/ordering); **technician performance tracking**;
**inventory allocation to WO** (bindings emitted as event only); **PWA photo capture**
(server API exists, PWA never uploads). **SLA 🟡** by priority not per-job-type, no breach
event/report. **Reporting 🟡** dispatcher list only.

**Fidelity:** seeded for operator `WIK` (Senegal POC fixtures) not Wananchi KE;
`initial_reason`/`final_reason` free-text not job-type-filtered pick-lists; no WO sub-status
enum (ACT/INA/STF…) driving shifting side-effects; optical/signal fields only inside note
payload; Field Audit over-built (doc: not used in KE).

### 3.7 Provisioning — `Modules/Provisioning`

**Strong (framework):** idempotent command ledger + attempts (`ProvisioningCommand`,
`ProvisioningService`), per-target swappable adapter seam (`ProvisioningAdapterRegistry`),
desired/observed **reconciliation** (`ReconciliationService::run`, `sophix:provisioning:reconcile`),
**NOC force-sync** approval-gated + audited (`requestForceSync/approveForceSync/executeForceSync`),
async poll worker, activation gating (`FulfillmentCallHandler` fails the flow if NMS rejects).

**Gaps 🔴:** **no vendor adapters** — only `StubProvisioningAdapter`; every target
(`HUAWEI_NCE_GPON_KE`, `CMTS_HFC_KE`, `SIP_VOICE_KE`) points at the stub. No service-specific
semantics (ONT slot/PON/logical-SN, DOCSIS class-of-service, Verimatrix smartcard/entitlement,
VoipSwitch SIP/MGCP creds + number pool, HFC 3-MAC). **IP assignment** unmodelled;
**bandwidth-profile** is free-text passthrough (no BH-service↔NMS-profile mapping table);
**notifications** (outage/queue-saturation/NOC alerts) and **SLA monitoring** absent; **retry**
has policy columns but no executor (schedule block commented out).

**Fidelity:** flat `target_code` vs the doc's Headend+Hub→AlphaCode routing / "1 OLT = 1
Headend = 1 Controller" queue isolation; untyped `desired_state` blob loses the per-service
contract; reconciliation compares a single status string, not profile-level drift. ⚪ RADIUS,
device-auth, auto-prov, bulk correctly out of scope per doc.

### 3.8 HPRFS / Integration Engine / Equipment — `Modules/Catalog`, `Modules/Osr`, `app/Foundation`

**Strong:** Home Pass entity + structured address + duplicate prevention + bulk import
(`HomePass`, `CatalogService::bulkImportHomePasses`), tech-region hierarchy + contractor
mapping (`TechRegion`, `TechCoverageService`), event outbox/inbox + dispatcher
(`OutboxEventBus`, `DispatchOutboxCommand`), file storage (`FileStorageService`), tax-invoice
signing (`TaxSigningService`, pluggable). **OSR is broad:** SKU catalog, serialized instance
registry + 9-state lifecycle, stock locations/movements/balances + approvals, reservations,
BOM auto-reserve, RMA/swap flow, procurement (PO→receipt→instances), inventory audit.

**Gaps 🔴:** HPRFS **construction-request lifecycle** (submit→approval→contractor→ROE/MDU→
design→create) entirely absent; **Homes Modification Utility** (bulk status/region edit,
clone) — only bulk *create*; persisted **Home Change History** (events only, no queryable
table); **Onboarding Apps Integration** (no composite onboarding API); **Data Warehousing**
(only internal metric-mart CSV, not ETL); **BH↔NMS equipment reconciliation** (A.1.26 fraud
audit). **Gateway 🟡** inbound-only (no outbound M-Pesa STK/paybill, no credential vault, no
statement reconciliation). **Equipment anchor 🟡** customer/subscription binding only — no
Head End/Hub/Unit/Port/CAS fields, no Sec ID/Chassis ID/per-instance warranty.

**Fidelity:** **"Invoice Signing" name collision** — code does KRA tax-invoice *fiscalization*;
the Confluence page describes **contract e-Signature** (DocuSign-style) which is **not** built.
Both target deep-dives (Equipment, Integration Engine) are unwritten stubs, so code is in
places ahead of documented requirements. Live tax signer is a stub (`StubTaxSigner`); HomePass
`technology` single enum (no mixed-area model); `active_termination_points` data-only (no
contractor-payment compute); HomePass status flag-driven but transition-free (no allowed-graph).

---

## 4. What is correctly out of scope

The workshop pages explicitly mark several features "not used by Wananchi": **Auto Pay,
Agent Payments, Deposit Handling, Write-Off, Late Fee, Contract Billing, Bill Dispute (own
module), Multi-currency, Multi-location accounts, Customer Credit Limit, RADIUS, Device
Authorization, Auto-Provisioning, Bulk Provisioning, Parts Management.** The codebase
correctly omits dedicated implementations for these — they should not be counted as gaps.

---

## 5. Method & caveats

- Requirements were read from the live Confluence space (`getConfluencePage`, markdown) across
  ~150 feature pages, 14 deep dives, module zooms and flows; the codebase was inspected
  module-by-module (models/services/controllers/migrations/routes/tests).
- Ratings are a first-pass coverage judgement at the feature level with code citations, not a
  line-by-line conformance audit. The diagrams embedded in the Confluence pages (sequence/state
  images) were not OCR'd; narrative + tables were the basis.
- Several Confluence pages carry open questions (⚪) where Wananchi has not yet confirmed
  business-rule values (grace days, per-wallet dunning, one-vs-two invoice for triple-play).
  Those remain open on both sides.
