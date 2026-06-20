# Billing — As-Built Design

> **Capability codes:** BIL-01 (charging), BIL-02-GEN-01 (invoice generation), BIL-02-ADJ-01
> (adjustments), BIL-02-TAX-01 (fiscal tax invoices), BIL-03 (cycle close), BIL-04 (dunning),
> BIL-05 (wallets), BIL-CFG-01 (billable-event catalog) · **Module path:** `Modules/Billing`
> **Source-of-truth tests:** `Modules/Billing/tests/Feature/*` (CycleClose, CycleBilling, Payment,
> Dunning, Adjustment, ProForma, BulkReversal+FailureQueue, Tax01, Wallet*, BillableEventCatalog,
> UsageTariff, MediationRating)

## 1. Purpose & boundaries
- **Owns:** all **money state** — invoices & lines, payments & allocations, wallets & transactions,
  dunning state, adjustments (credit/debit notes), fiscal tax invoices, account credit balance.
- **Does NOT own:** prices/tax/discount **config** (Catalog), the subscription master (Subscription),
  payment-rail I/O (PaymentGateway). It computes and settles; it reads config and reacts to events.
- **Job:** turn a subscription's cycle (and lifecycle fees) into settled money — compute → invoice →
  collect → dun → adjust — keeping postpaid (invoice) and prepaid (wallet) models distinct.

## 2. Data model (selected)
| Table | Purpose | Key invariants |
| --- | --- | --- |
| `invoice` / `invoice_line` | structured invoices (SUMMARY/DETAIL, `service_category_code`, `package_ref`, `wallet_type_code`), grouped per `grouping_dimension` | `legal_invoice_number` once issued; TAX invoices immutable |
| `payment_ledger` / `payment_allocation` | money in + allocation to invoices | dedup by `payment_reference` |
| `wallet` / `wallet_transaction` | prepaid balances (PLM-CFG-03 types) | balance never negative on debit |
| `billing_intent` | a lifecycle-operation fee/credit (pay-first gate) | `state_callback` drives a SUB-LM transition on settle |
| `billable_event` (+ `_category`) | BIL-CFG-01 catalog: what may be charged, sign policy, `pay_first_required`, `state_callback` | intent must resolve to an ACTIVE event |
| `dunning_program` / `dunning_state` | versioned dunning policy + per-account episode | program version **pinned** at entry (R-BIL-04-C-1); monotonic level advance |
| `adjustment_request` (+ approval steps / reason codes) | governed credit/debit-note pipeline | reason mandatory; routing via `rules.billing.adjustment-approval` |
| `pro_forma` | prepaid pre-cycle projection | one ACTIVE per (sub, cycle); regen sets `superseded_by` |
| `tax_invoice` (+ signing failure) | BIL-02-TAX-01 fiscal doc | inclusive decomposition; async signer |
| `rated_event` / `usage_record` | mediated/rated usage | `billed` flag links to the invoice |
| `account_credit_balance` | account-level credit | auto-drawn on new invoice |
| `generation_failure_queue` | recoverable generation failures | backoff → `GAVE_UP_AUTO` |

## 3. Services & responsibilities
| Service | Responsibility |
| --- | --- |
| `ChargeComputeService` | **what is owed** — `cycleCharges()` (package fee + per-category usage), `recurringFee()` (price + **days-based proration**, parameterised by `cyclePeriod`) |
| `CycleCloseService` | BIL-03 boundary — `closeCycle()`: settle (postpaid invoice / prepaid wallet), advance the anchor, idempotent per window; failures → `GenerationFailureService` |
| `InvoiceService` | BIL-02-GEN-01 — assemble structured invoices from charges, **WALLET/PACKAGE/etc. grouping** (`grouping_dimension`, `Charge::groupKey`), issue credit/debit notes |
| `BillingIntentService` | BIL-01 lifecycle-fee gate — `emit()`: validate vs billable-event catalog, charge (wallet/invoice/credit), pay-first parks PENDING; `confirm()` + **`state_callback`** → SUB-LM transition |
| `AdjustmentService` | BIL-02-ADJ-01 — propose → approve (rules-routed steps) → issue note → apply |
| `DunningService` (+ `DunningProgramResolver`) | BIL-04 — `scan()`/`assessAccount()`: advance levels at grace (waived by an `affects_dunning` flag), fire level actions (warn/restrict/suspend/terminate) |
| `WalletService` | BIL-05 — wallet debit/credit/topup/expiry, prepaid settlement |
| `ProFormaService` | BIL-02-GEN-01 Gen-3 — prepaid pre-cycle projection (Day-25), supersede chain |
| `GenerationFailureService` | the retry queue — `enqueue()` + `retryDue()` (backoff, GAVE_UP_AUTO) |
| `PaymentService` | allocation, overpayment→credit, reversal |
| `TaxInvoiceGenerator` / `TaxService` / `TaxSigningService` | BIL-02-TAX-01 — inclusive decomposition (via Catalog `TaxComputeService`) + async fiscal signing |
| `BulkReversalService` | dual-controlled bulk invoice reversal |
| `MediationRatingService` / `CustomerSnapshotService` | usage rating; immutable customer snapshot on docs |

## 4. API surface
Invoices, payments, wallets, adjustments, billable-events, bulk-reversals, dunning-run under `/api/`.
Writes `permission:billing.*`; payment posting is idempotent (gateway_ref or Idempotency-Key).
*(Expand the exact route table per `_TEMPLATE.md` §4.)*

## 5. Integration (events) — topic `billing.money`
- **Emits:** `InvoiceGenerated`/`InvoicePaid`/`InvoiceCancelled`, `Cycle{Closed,Activated,PaymentMissed}`,
  `Payment{Received,Applied,Reversed}`, `Wallet{Credited,Debited,ToppedUp}`, `TaxInvoice{Issued,Signed,…}`,
  `DunningStageAdvanced`/`DunningCleared`/`SubscriptionEnteredDunning`/`SubscriptionSuspendedForNonPayment`,
  `Adjustment{Proposed,Approved,Rejected}` + note events.
- **Consumes (`OutboxEventPublished`):** `TaxEventBridge` (payment moments → tax invoice),
  `DunningEventBridge` (CyclePaymentMissed → dunning; pause/resume; WalletToppedUp → recovery),
  `RetryFrozenCycleOnTopup`, `ApplyCreditBalanceOnInvoice`, `EvictPlmCatalogCache`.

## 6. Processes (scheduled workers, not BPMN)
`routes/console.php` + `BillingRuntimeProvider`: `sophix:billing:cycle-close` (30 min),
`:dunning-run` (daily), `:pro-forma` (daily), `:generation-retry` (15 min), `:wallet:expire`,
`:tax-sign`/`:tax-retry`, `:rate-usage`, `:run-cycle`. These are the heartbeat that advances money state.

## 7. Policy & config (no-code knobs)
- **`rules.billing.adjustment-approval`** decision table (fallback derives steps from
  `adjustment_limits_config`).
- `billable_event` catalog (sign policy, pay-first, `state_callback`), `dunning_program` (versioned
  levels/grace/actions), `adjustment_reason_code`/limits, tax via Catalog, cycle cadence on the
  subscription master, invoice `grouping_dimension`.

## 8. Cross-module dependencies
- **Reads →** Catalog (`package`/`service` for charges, `TaxComputeService` for tax, discount engine).
- **Drives →** Subscription (BillingIntent `state_callback` transitions; dunning suspend/restrict via
  `OperationFramework`), OSR (deposit forfeiture intents), Notification (dunning/tax notices).
- **Reacts to →** Subscription lifecycle (cycle anchoring), Payment events.

## 9. Invariants & rules (examples)
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-BIL-03-C-5 | a window closes at most once (idempotent per `current_cycle_end`) | `CycleCloseService::closeCycle` |
| R-BIL-03-C-3/W-1 | prepaid missed payment FREEZES the anchor + drives dunning; unfreezes on top-up | `CycleCloseService`, `RetryFrozenCycleOnTopup` |
| R-BIL-04-C-1 | dunning program version pinned at entry | `DunningService` |
| R-BIL-04-D-2 | dunning advances exactly one level per pass (monotonic) | `DunningService::assessAccount` |
| R-GEN-01-Q-2/3 | failed generations retried with backoff, then `GAVE_UP_AUTO` | `GenerationFailureService::retryDue` |
| R-BIL-01-SC-1 | a settled billable-event `state_callback` transitions the subscription | `BillingIntentService` |
| R-GEN-01-R-1 | bulk reversal is dual-controlled (requester ≠ approver) | `BulkReversalService` |

## 10. Open items / deltas from `docs/design-text/`
- **Discount auto-application at cycle close (the one real revenue-path item):** `ChargeComputeService`
  does **not** insert a discount line during the recurring run; discounts reach the customer via the
  **adjustment/credit** path instead. If the operator wants discount-as-invoice-line, add a generic
  discount-compute stage to the charge pipeline (config thereafter). Severity = depends on model.
- **Tax on notes:** `issueNote` writes notes with `tax_amount = 0` (treats the amount as inclusive) —
  a modelling choice to revisit if notes must decompose tax.
- **Proration scope:** `recurringFee` prorates the **cycle fee** (short first/last cycle); **mid-cycle
  MACD proration** runs through the BillingIntent path — document both together.
- Advisory-only columns exist by design (`billable_event_category.is_credit`,
  `gl_account_hint`) — informational, not behaviour.
