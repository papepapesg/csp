# Billing — As-Built Design

> **Capability codes:** BIL-01 (charging), BIL-02-GEN-01 (invoice generation), BIL-02-ADJ-01
> (adjustments), BIL-02-TAX-01 (fiscal tax invoices), BIL-03 (cycle close), BIL-04 (dunning),
> BIL-05 (wallets), BIL-CFG-01 (billable-event catalog) · **Module path:** `Modules/Billing`
> **Tests:** `CycleClose`, `CycleBilling`, `Payment`, `Dunning`, `Adjustment`, `ProForma`,
> `BulkReversalAndFailureQueue`, `Tax01`, `Wallet*`, `BillableEventCatalog`, `UsageTariff`, `MediationRating`

## 1. Purpose & boundaries
- **Owns:** all **money state** — invoices & lines, payments & allocations, wallets & transactions,
  dunning, adjustments (credit/debit notes), fiscal tax invoices, account credit balance, the
  billable-event catalog, the generation-failure queue.
- **Does NOT own:** prices/tax/discount **config** (Catalog), the subscription master (Subscription),
  the payment rail (PaymentGateway). It computes + settles; reads config; reacts to events.
- **Job:** compute → invoice → collect → dun → adjust, keeping POSTPAID (invoice) and PREPAID (wallet)
  distinct.

## 📖 Scenarios (service + Foundation involvement)

### 1. Postpaid triple-play cycle closes → two invoices
`sophix:billing:cycle-close` (**Foundation/Console** schedule, 30 min) → `CycleCloseService::closeCycle`
→ `ChargeComputeService::cycleCharges` (package fee + VOICE usage). `InvoiceService::generateFromCharges`
reads `grouping_dimension=WALLET` → writes **two** `invoice` rows (Internet/TV + Voice), each
tax-decomposed via Catalog `TaxComputeService`; emits `InvoiceGenerated` (**outbox**). Anchor advances;
`rated_event.billed=true` linked to the voice invoice. *Proven by `CycleCloseTest`.*

### 2. Prepaid cycle, wallet short → freeze + enter dunning (cross-module via outbox)
Same close, PREPAID: `WalletService::settleFromWallets` finds the balance short → `closeCycle` does
**not** advance; emits `CyclePaymentMissed`. `DunningEventBridge` (listener on `OutboxEventPublished`)
→ `DunningService` enters the account at level 1 (`SubscriptionEnteredDunning`). *Foundation: outbox →
listener.* *Proven by `CycleCloseTest`, `DunningTest`.*

### 3. M-Pesa payment lands → allocate → confirm intent (cross-module chain)
PaymentGateway emits `PaymentReceived` → `PaymentService` writes a `payment_ledger` row, allocates to
open invoices (`payment_allocation`), marks the invoice `PAID`, emits `InvoicePaid`. Subscription's
`ConfirmBillingIntentOnPayment` then confirms a pay-first `billing_intent` and **correlates a workflow
message** (`Foundation/Workflow`) to resume a parked operation. *Proven by `PaymentApplicationTest`.*

### 4. Overpayment → account credit → auto-draw next invoice
A payment exceeds the invoice → `PaymentService` posts the surplus to `account_credit_balance` (emits
`OverpaymentPendingReview`/`CreditBalanceAdjusted`). Next `InvoiceGenerated` → `ApplyCreditBalanceOnInvoice`
(listener) auto-draws the credit. *Foundation: event-driven credit application.*

### 5. Paid reconnection fee flips the subscription ACTIVE (state_callback)
A `RECONNECTION_FEE_AFTER_DUNNING` `billable_event` has `state_callback{targetStatus:ACTIVE}`.
`BillingIntentService::emit` raises it pay-first (`billing_intent.status=PENDING`); on `InvoicePaid`,
`confirm()` reads the pinned callback → `SubscriptionService::transitionStatus(ACTIVE)`. *Proven by
`BillableEventCatalogTest::test_paid_state_callback_transitions_the_subscription`.*

### 6. Credit-note adjustment → propose → approve → apply (approvals + rules)
`AdjustmentService::propose` (reason mandatory) asks `rules.billing.adjustment-approval`
(**Foundation/Rules**) for `stepsRequired`; >0 ⇒ `adjustment_request.status=PENDING_APPROVAL`. Approvers
sign steps (SoD); when met → `approveAndApply` issues a `CREDIT_NOTE` invoice (GEN-01) and applies it
(CN-01). *Proven by `AdjustmentTest`.*

### 7. Dunning escalates faster on an NPD flag, then suspends
`sophix:billing:dunning-run` → `DunningService::assessAccount`: an ILM `affects_dunning` flag
(`AccountService::hasDunningAccelerantFlag`) **waives the grace window** → advance a level now; the
level action fires `RESTRICTION_ADD`/`SUSPEND_NP` via Subscription `OperationFramework`. *Cross-module
read + workflow.* *Proven by `DunningTest`.*

### 8. Cycle generation fails → retry queue → give up
`closeCycle` throws (snapshot/tax/BIL01 unavailable) → `GenerationFailureService::enqueue` writes a
`generation_failure_queue` row (`PENDING_RETRY`, backoff). `sophix:billing:generation-retry` (15 min)
re-invokes the generator; persistent failure → `GAVE_UP_AUTO` for human review. *Proven by
`BulkReversalAndFailureQueueTest`.*

### (bonus) 9. Tax invoice signed asynchronously
A payment moment → `TaxEventBridge` → `TaxInvoiceGenerator` issues a `tax_invoice` (inclusive
decomposition), then `sophix:billing:tax-sign` calls the signer; failure → `tax-retry` backoff →
`TaxInvoiceSigningGaveUp`. *Proven by `Tax01Test`.*

## 2. Data model — ≥4 sample rows + readings

### `invoice` · `type`: `STANDARD|TAX|CREDIT_NOTE|DEBIT_NOTE` · `status`: `OPEN|PARTIALLY_PAID|PAID|VOID|OVERDUE`
```json
{ "invoice_id":"inv_1","type":"STANDARD","status":"OPEN","billing_mode":"POSTPAID","total_amount":5000,"amount_due":5000,"due_date":"2026-07-15","legal_invoice_number":"INV-WIK-2026-000123" }
{ "invoice_id":"inv_2","type":"STANDARD","status":"PAID","total_amount":1500,"amount_due":0 }
{ "invoice_id":"inv_3","type":"CREDIT_NOTE","status":"ISSUED","original_invoice_id":"inv_1","total_amount":800,"amount_due":0 }
{ "invoice_id":"inv_4","type":"TAX","status":"ISSUED","total_amount":5000,"legal_invoice_number":"TAX-WIK-2026-000045" }
```
**Reading:** inv_1 is owed (`OPEN`, due-dated → becomes dunnable past due). inv_2 is settled. inv_3 is a
**credit note** linked to inv_1 (so inv_1 can't be bulk-reversed — it has linked notes). inv_4 is a
signed **TAX** invoice — immutable, never adjusted (adjust the commercial one instead).

### `invoice_line` · `line_type`: `SUMMARY|DETAIL`
```json
{ "id":"il_1","invoice_id":"inv_1","line_type":"SUMMARY","service_category_code":"SUBSCRIPTION","package_ref":"pkg_triple","subtotal":4200,"tax_amount":672 }
{ "id":"il_2","invoice_id":"inv_1","line_type":"DETAIL","parent_summary_line_id":"il_1","service_category_code":"INTERNET","subtotal":3000 }
{ "id":"il_3","invoice_id":"inv_1","line_type":"DETAIL","parent_summary_line_id":"il_1","service_category_code":"TV","subtotal":1200 }
{ "id":"il_4","invoice_id":"inv_5","line_type":"DETAIL","service_category_code":"VOICE","subtotal":300,"wallet_type_code":"VOICE_WALLET" }
```
**Reading:** il_1 is the customer-facing **SUMMARY** (package), il_2/il_3 its **DETAIL** breakdown
(Internet+TV priced as the package). il_4 is voice usage on a *different* invoice (WALLET grouping put
voice on its own document, routed to `VOICE_WALLET`).

### `payment_ledger` · `method`: `MPESA|VISA|BANK_TRANSFER|OFFLINE`
```json
{ "payment_id":"pay_1","account_id":"acc_1","paid_amount":5000,"method":"MPESA","payment_reference":"QGR7Xk...","status":"APPLIED" }
{ "payment_id":"pay_2","account_id":"acc_1","paid_amount":6000,"method":"MPESA","status":"APPLIED" }
{ "payment_id":"pay_3","account_id":"acc_2","paid_amount":1500,"method":"OFFLINE","payment_reference":"cash-rcpt-9","status":"APPLIED" }
{ "payment_id":"pay_4","account_id":"acc_1","paid_amount":5000,"method":"VISA","status":"REVERSED" }
```
**Reading:** every payment carries a **dedup reference** (gateway ref or Idempotency-Key) so a retried
callback never double-applies. pay_2 overpaid (6000 > invoice) → surplus → `account_credit_balance`.
pay_3 is cash (OFFLINE, reference = the receipt). pay_4 was reversed (chargeback).

### `billing_intent` · `intent_type`: `PRORATION|PAUSE_FEE|RECONNECTION_FEE|DEPOSIT_REFUND|…` · `status`: `PENDING|CHARGED|CONFIRMED|WAIVED|REFUNDED` · `settlement_channel`: `INVOICE|WALLET|CREDIT|NONE`
```json
{ "intent_id":"bint_1","subscription_id":"sub_1","intent_type":"PRORATION","amount":350.0,"status":"CONFIRMED","settlement_channel":"INVOICE","pay_first":false }
{ "intent_id":"bint_2","subscription_id":"sub_1","intent_type":"RECONNECTION_FEE","amount":500.0,"status":"PENDING","pay_first":true,"state_callback":{"targetStatus":"ACTIVE"},"invoice_id":"inv_9" }
{ "intent_id":"bint_3","subscription_id":"sub_2","intent_type":"DEPOSIT_REFUND","amount":-2500.0,"status":"CONFIRMED","settlement_channel":"CREDIT" }
{ "intent_id":"bint_4","subscription_id":"sub_3","intent_type":"PAUSE_FEE","amount":0.0,"status":"CONFIRMED","settlement_channel":"NONE" }
```
**Reading:** bint_2 is **pay-first** (`PENDING` until `inv_9` is paid; then its `state_callback` flips the
sub ACTIVE). bint_3 is a **negative** amount (credit) → posts to account credit (`CREDIT` channel).
bint_4 is a zero/skip (the event applied but nothing to charge → `NONE`).

### `billable_event` · `amount_sign_policy`: `POSITIVE_ONLY|NEGATIVE_ONLY|SIGNED` · `trigger_type`: `SAGA_INTENT|LIFECYCLE_EVENT|ADMIN_ACTION|CUSTOMER_PURCHASE|EXTERNAL_PAYMENT|SCHEDULED` · `status`: `DRAFT|ACTIVE|RETIRED`
```json
{ "id":"bev_1","code":"RECONNECTION_FEE_AFTER_DUNNING","amount_sign_policy":"POSITIVE_ONLY","trigger_type":"EXTERNAL_PAYMENT","pay_first_required":true,"state_callback":{"transitionCode":"RECONNECT_AFTER_FEE","targetStatus":"ACTIVE"},"status":"ACTIVE" }
{ "id":"bev_2","code":"INSTALLATION_FEE","amount_sign_policy":"POSITIVE_ONLY","trigger_type":"SAGA_INTENT","trigger_intent_code":"INSTALL_FEE_INTENT","status":"ACTIVE" }
{ "id":"bev_3","code":"DEPOSIT_REFUND","amount_sign_policy":"NEGATIVE_ONLY","trigger_type":"LIFECYCLE_EVENT","status":"ACTIVE" }
{ "id":"bev_4","code":"GOODWILL_CREDIT","amount_sign_policy":"NEGATIVE_ONLY","trigger_type":"ADMIN_ACTION","status":"DRAFT" }
```
**Reading:** the catalog **governs what BIL-01 may charge**. bev_1 is pay-first with a state_callback
(reconnection). bev_3 is `NEGATIVE_ONLY` (a refund — a positive amount is rejected). bev_4 is `DRAFT`
(not yet chargeable). An intent whose type doesn't resolve to an `ACTIVE` event is rejected.

### `dunning_program` (`billing_mode`: `POSTPAID|PREPAID|PREPAYMENT`) & `dunning_state` (`status`: `ACTIVE|CLEARED|SUSPENDED_BY_PAUSE|PENDING_TERMINATION_REVIEW|RECOVERY_FAILED|ARCHIVED`, `current_level` int)
```json
// program (versioned; pinned on the state at entry)
{ "id":"dprg_1","code":"wik_postpaid_standard","version":1,"billing_mode":"POSTPAID","pre_termination_review_required":true,"level_definitions":[{"level":1,"name":"WARNING","grace_period_days":7,"action_workflow_intent":"WARNING_ONLY"},{"level":2,"name":"RESTRICTED","grace_period_days":7,"action_workflow_intent":"RESTRICTION_ADD"},{"level":3,"name":"SUSPENDED","grace_period_days":14,"action_workflow_intent":"SUSPEND_NP"},{"level":4,"name":"TERMINATED","action_workflow_intent":"TERMINATION"}] }
// state
{ "dunning_id":"dun_1","account_id":"acc_1","current_level":1,"status":"ACTIVE","dunning_program_ref":"wik_postpaid_standard","dunning_program_version":1,"outstanding_debt_amount":5000 }
{ "dunning_id":"dun_2","account_id":"acc_2","current_level":3,"status":"ACTIVE","outstanding_debt_amount":12000 }
{ "dunning_id":"dun_3","account_id":"acc_3","current_level":0,"status":"CLEARED" }
{ "dunning_id":"dun_4","account_id":"acc_4","current_level":4,"status":"PENDING_TERMINATION_REVIEW" }
```
**Reading:** the program is **versioned** and **pinned** on the state at entry (R-BIL-04-C-1) — editing
the policy never disturbs in-flight episodes. dun_1 is at WARNING; dun_2 reached SUSPENDED; dun_3
recovered (`CLEARED`, level 0); dun_4 hit terminate but is parked for the mandatory review window.

### `adjustment_request` · `direction`: `CREDIT|DEBIT` · `scope`: `FULL|LINE|AMOUNT` · `status`: `PROPOSED|PENDING_APPROVAL|APPROVED|APPLIED|REJECTED|APPLICATION_FAILED|CANCELLED_BY_PROPOSER`
```json
{ "adjustment_id":"adj_1","direction":"CREDIT","scope":"FULL","parent_invoice_id":"inv_1","amount":5000,"reason_code":"SLA_COMPENSATION","status":"APPLIED","note_invoice_id":"inv_3" }
{ "adjustment_id":"adj_2","direction":"DEBIT","scope":"LINE","parent_invoice_id":"inv_2","line_ref":"il_4","amount":300,"reason_code":"BILLING_ERROR","status":"PENDING_APPROVAL","required_approvals":2 }
{ "adjustment_id":"adj_3","direction":"CREDIT","scope":"AMOUNT","service_category_code":"GOODWILL","amount":1000,"reason_code":"GOODWILL","status":"PROPOSED" }
{ "adjustment_id":"adj_4","direction":"CREDIT","scope":"FULL","parent_invoice_id":"inv_2","amount":1500,"reason_code":"DISPUTE","status":"REJECTED" }
```
**Reading:** scope drives the amount source — `FULL`=parent total, `LINE`=a specific line (capped),
`AMOUNT`=free-form (needs a finance category). adj_1 is applied (a `CREDIT_NOTE` `inv_3` was issued).
adj_2 needs 2 approvals (multi-step). A reason code is always mandatory; its `direction` must justify
the note direction.

### `wallet` & `wallet_transaction` (PREPAID)
```json
{ "wallet_id":"wal_1","subscription_id":"sub_2","wallet_type_code":"MAIN_WALLET","balance":1200,"currency":"KES","status":"ACTIVE" }
{ "wallet_id":"wal_2","subscription_id":"sub_2","wallet_type_code":"VOICE_WALLET","balance":0,"status":"ACTIVE" }
{ "wallet_id":"wal_3","subscription_id":"sub_5","wallet_type_code":"MAIN_WALLET","balance":50,"status":"EXPIRED" }
{ "wtx_1":"…","wallet_id":"wal_1","type":"TOPUP","amount":1000 }
```
**Reading:** a prepaid sub may carry several typed wallets (MAIN, VOICE) routed by `wallet_type_code`;
cycle close debits the matching wallet. wal_2 empty → a voice cycle would freeze (Scenario 2). wal_3
expired (its balance is swept by `sophix:wallet:expire`). Transactions are the per-move ledger.

### `pro_forma` (`status`: `ACTIVE|SUPERSEDED`), `rated_event`, `account_credit_balance`
```json
{ "pro_forma_id":"pf_1","subscription_id":"sub_2","status":"ACTIVE","cycle_end":"2026-07-01","total_amount":1500,"superseded_by":null }
{ "pro_forma_id":"pf_0","subscription_id":"sub_2","status":"SUPERSEDED","superseded_by":"pf_1" }
{ "rated_id":"rat_1","subscription_id":"sub_1","tariff_code":"VOICE_LOCAL","amount":12.5,"billed":false }
{ "account_id":"acc_1","balance":1000,"currency":"KES" }
```
**Reading:** the prepaid **pro forma** projects next cycle (Day-25); regenerating supersedes the prior
one and records `superseded_by` (the chain). `rated_event.billed=false` = unbilled usage the next cycle
close will sweep. `account_credit_balance` is auto-drawn on the next invoice.

## 3. Services (worked calls)
| Service | Responsibility |
| --- | --- |
| `ChargeComputeService` | `cycleCharges()` (fee + usage), `recurringFee()` (price + days-proration) |
| `CycleCloseService` | `closeCycle()` — settle, advance anchor, idempotent per window |
| `InvoiceService` | assemble structured invoices, grouping, issue notes |
| `BillingIntentService` | lifecycle-fee gate (`emit`/`confirm`, `state_callback`) |
| `AdjustmentService` | propose → approve (rules-routed) → issue note → apply |
| `DunningService` (+`DunningProgramResolver`) | level state machine + actions |
| `WalletService` | wallet debit/credit/topup/expiry |
| `ProFormaService` / `GenerationFailureService` / `PaymentService` / `TaxInvoiceGenerator` / `BulkReversalService` | pro forma · retry queue · allocation · fiscal signing · dual-controlled reversal |

## 4. API surface
Invoices, payments, wallets, adjustments, billable-events, bulk-reversals, dunning-run under `/api/`;
`permission:billing.*`; payment posting idempotent (gateway_ref or Idempotency-Key).

## 5. Integration (events) — topic `billing.money`
- **Emits:** `Invoice{Generated,Paid,Cancelled}`, `Cycle{Closed,Activated,PaymentMissed}`,
  `Payment{Received,Applied,Reversed}`, `Wallet{Credited,Debited,ToppedUp}`, `TaxInvoice{Issued,Signed}`,
  `DunningStageAdvanced`/`DunningCleared`/`SubscriptionSuspendedForNonPayment`, `Adjustment{Proposed,
  Approved,Rejected}` + note events.
- **Consumes:** `TaxEventBridge`, `DunningEventBridge`, `RetryFrozenCycleOnTopup`,
  `ApplyCreditBalanceOnInvoice`, `EvictPlmCatalogCache`.

## 6. Processes (scheduled workers)
`cycle-close` (30m), `dunning-run` (daily), `pro-forma` (daily), `generation-retry` (15m),
`wallet:expire`, `tax-sign`/`tax-retry`, `rate-usage`. The money heartbeat.

## 7. Policy & config
`rules.billing.adjustment-approval`; `billable_event` catalog; versioned `dunning_program`;
`adjustment_reason_code`/limits; tax via Catalog; cycle cadence on the sub; invoice `grouping_dimension`.

## 8. Cross-module dependencies
- **Reads →** Catalog (`package`/`service` charges, `TaxComputeService`, discount engine).
- **Drives →** Subscription (`state_callback`, dunning suspend/restrict), OSR (deposit forfeiture),
  Notification (dunning/tax notices). **Reacts to →** Subscription lifecycle, Payment events.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-BIL-03-C-5 | a window closes at most once | `CycleCloseService::closeCycle` |
| R-BIL-03-C-3/W-1 | prepaid miss freezes anchor + dunning; unfreezes on top-up | `CycleCloseService`, `RetryFrozenCycleOnTopup` |
| R-BIL-04-C-1 | dunning program version pinned at entry | `DunningService` |
| R-BIL-04-D-2 | dunning advances one level per pass | `assessAccount` |
| R-GEN-01-Q-2/3 | failed generations retried then `GAVE_UP_AUTO` | `GenerationFailureService::retryDue` |
| R-BIL-01-SC-1 | settled `state_callback` transitions the subscription | `BillingIntentService` |
| R-GEN-01-R-1 | bulk reversal dual-controlled (requester ≠ approver) | `BulkReversalService` |

## 10. Open items / deltas
- **Discount auto-application at cycle close** is the one real revenue-path item — discounts reach
  customers via the adjustment/credit path, not an auto invoice line (see `catalog.md`).
- Notes write `tax_amount=0` (inclusive treatment) — a modelling choice to revisit.
- `recurringFee` prorates the cycle fee; **mid-cycle MACD proration** runs via the BillingIntent path.
- Advisory-only columns by design: `billable_event_category.is_credit`, `gl_account_hint`.
