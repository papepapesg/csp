# ILM — As-Built Design (Customer & CVM)

> **Capability codes:** ILM-CFG-01 (customer/account/360/KYC/flags), EM-03 (CVM retention) ·
> **Module path:** `Modules/Ilm` · **Tests:** `CustomerApi`, `CustomerOverview`, `AccountFlag`,
> `Cvm`, `CvmFlagEvaluator`

## 1. Purpose & boundaries
- **Owns:** the **customer** & **customer-account** masters, the KYC chain, the **account-flag** &
  **sub-status** catalogs, the interaction timeline, and the **CVM** engine (segments/activities/offers).
- **Does NOT own:** subscriptions, money, equipment. It is the **system of record for "who the customer
  is and what state the account is in"** and the source of the shared routing context.
- **Job:** authoritative customer/account data + a data-driven flag/sub-status model + governed CVM.

## 📖 Scenarios (service + Foundation involvement)

### 1. Create a customer and run two-step KYC
`POST /api/customers` → `CustomerService::create` (emits `CustomerCreated`). KYC docs uploaded via the
**Foundation/Files** store. `recordKycDecision(customer, level, 'APPROVED')` — level authority is config
(`kyc_approval_role`); two approvals flip `kyc_status` `PENDING → KYC_L1_APPROVED → APPROVED` and emit
`CustomerKycApproved`. *Cross-module: Fulfillment's `ResumeOrderOnKycApproved` resumes a parked order.*
*Proven by `CustomerApiTest`.*

### 2. Sub-status change driven by the catalog (approval-gated)
`PATCH …/accounts/acc_1 {sub_status:'hold'}` → `AccountService::update`: the
`customer_sub_status_catalog` validates the code, **derives** `main_status` (clone), and because
`requires_approval=true` it rejects without an `approval_reference` (R-ILM-S-2). With one it commits +
writes `account_status_history` + emits `CustomerAccountStatusChanged`. *Proven by `AccountFlagTest`.*

### 3. Raise an NPD flag → attention banner + faster dunning
`PUT …/flags/NPD` → `setFlag`: catalog-gated; sets `attention_banner` and emits `CustomerAccountFlagSet`.
Because the catalog marks NPD `affects_dunning=true`, BIL-04's next scan calls
`hasDunningAccelerantFlag` → **waives the grace window** (R-ILM-F-3). *Cross-module read.* *Proven by
`AccountFlagTest`, `DunningTest`.*

### 4. FRAUD_SUSPECTED flag blocks activation
A `FRAUD_SUSPECTED` flag (`affects_provisioning=true`) → Fulfillment's `TriggerActivationHandler` calls
`hasProvisioningBlockingFlag` → refuses to activate (R-ILM-F-4) until cleared. *Proven by
`FulfillmentJourneyTest::test_fraud_suspected_flag_blocks_activation`.*

### 5. Account status change cascades to the network + the customer
A provisioning-affecting status change emits `CustomerAccountStatusChanged{affectsProvisioning,
customerVisible}` to the **outbox** → Provisioning's `SyncProvisioningOnAccountStatusChanged`
re-broadcasts the network, and Notification's `AccountStatusNotificationBridge` notifies the customer.
*Foundation: one event, two reactions.* *Proven by `ReconciliationTest`, `Not01PipelineTest`.*

### 6. CVM evaluate → segment → activity (idempotent)
`POST /api/cvm/customers/CUS-1/evaluate {signals}` → `CvmEvaluationService`: writes a
`cvm_signal_profile`, computes churn score, assigns a `cvm_segment_membership` (e.g.
`RETENTION_HIGH_RISK`), and opens a `cvm_activity` — **idempotent by source event** (same event → same
activity). Emits `CvmCustomerEvaluated`/`CvmActivityCreated`. *Proven by `CvmTest`.*

### 7. Retention offer over threshold → EM-CFG-04 → accept → SIP-03
`CvmOfferService::propose` (25% discount): `rules.cvm.offer` says over threshold → `requireApproval`
→ offer `PENDING_APPROVAL` (an EM-CFG-04 request). `accept()` returns **409** while pending. Approval →
`ResumeCvmOfferOnApproval` → `applyApprovalOutcome` releases it `PROPOSED`; `accept` then creates a
Catalog `DiscountAssignment` (SIP-03) and records a `cvm_outcome`. *Proven by `CvmTest`.*

### 8. routingContext — the shared fact set
`AccountService::routingContext(accountId)` returns a plain map (operator, serviceClass, accountStatus,
subStatus, customerType, vip, flags…) so **any** module can feed a complete fact set into its rules
engine without reading ILM tables directly. *Shows: the cross-module decision-context pattern.*

### (bonus) 9. KYC rejected → the order is cancelled
`recordKycDecision(..,'REJECTED')` emits `CustomerKycRejected` → Fulfillment `CancelOrderOnKycRejected`
cancels + compensates the parked order. *Proven by `FulfillmentJourneyTest`.*

## 2. Data model — ≥4 sample rows + readings

### `customer` (`type`: `RES|COM` · `kyc_status`: `PENDING|KYC_L1_APPROVED|APPROVED|REJECTED`)
```json
{ "customer_id":"cust_1","type":"RES","name":"Jane Mwangi","kyc_status":"APPROVED","tax_identifier":"A012345678Z","primary_msisdn":"+254712000111" }
{ "customer_id":"cust_2","type":"RES","name":"Otieno","kyc_status":"PENDING" }
{ "customer_id":"cust_3","type":"COM","name":"Acme Ltd","kyc_status":"KYC_L1_APPROVED" }
{ "customer_id":"cust_4","type":"RES","name":"Fraudster","kyc_status":"REJECTED" }
```
**Reading:** `kyc_status` gates fulfillment activation — only `APPROVED` proceeds; `KYC_L1_APPROVED` is
mid-chain (needs L2); `REJECTED` cancels the order. `type` (RES/COM) is a segment proxy used in routing.
`tax_identifier` is what Billing's tax invoice reads.

### `customer_account` (`status`: `ACTIVE|INACTIVE`)
```json
{ "account_id":"acc_1","customer_id":"cust_1","status":"ACTIVE","sub_status":"active","service_class_1":"GOLD","attention_banner":null }
{ "account_id":"acc_2","customer_id":"cust_1","status":"ACTIVE","sub_status":"vip","attention_banner":null }
{ "account_id":"acc_3","customer_id":"cust_3","status":"INACTIVE","sub_status":"hold","attention_banner":"On hold pending docs" }
{ "account_id":"acc_4","customer_id":"cust_4","status":"INACTIVE","sub_status":"churned" }
```
**Reading:** the **main** `status` is a 2-value enum, **derived** from the sub-status catalog (`hold`/
`churned` clone to INACTIVE). `sub_status` carries the operator nuance (vip, hold, churned). The
`attention_banner` is set by an attention-surfacing flag and cleared when the last one is cleared. A
customer may hold several accounts.

### `customer_account_flag` (`state`: `ACTIVE|CLEARED`) & `…_flag_catalog`
```json
// catalog (value_kind: BOOLEAN|SCORE_0_100|TIER|COUNT ; effect booleans)
{ "flag_code":"NPD","value_kind":"BOOLEAN","evaluator":"DROOLS","surfaces_attention":true,"affects_dunning":true,"affects_provisioning":false,"customer_visible":false }
{ "flag_code":"FRAUD_SUSPECTED","value_kind":"BOOLEAN","evaluator":"MANUAL","surfaces_attention":true,"affects_provisioning":true }
{ "flag_code":"LOYALTY_TIER","value_kind":"TIER","evaluator":"EVENT_DRIVEN","customer_visible":true }
// instance
{ "account_id":"acc_3","flag_code":"NPD","state":"ACTIVE","bool_value":true,"source":"DROOLS" }
```
**Reading:** the **catalog** declares each flag's effects: `affects_dunning` (BIL-04 escalates faster),
`affects_provisioning` (FUL-03 blocks activation), `customer_visible` (shown to the customer),
`surfaces_attention` (drives the banner). `evaluator` says who may set it (MANUAL = BO only). The
instance is a per-account ACTIVE/CLEARED flag.

### `customer_sub_status_catalog`
```json
{ "sub_status_code":"active","main_status":"ACTIVE","requires_approval":false,"affects_provisioning":true,"customer_visible":true }
{ "sub_status_code":"vip","main_status":"ACTIVE","requires_approval":true,"affects_provisioning":false,"customer_visible":false }
{ "sub_status_code":"hold","main_status":"INACTIVE","requires_approval":true,"affects_provisioning":true,"customer_visible":false }
{ "sub_status_code":"churned","main_status":"INACTIVE","requires_approval":true,"affects_provisioning":true,"customer_visible":true }
```
**Reading:** this is the account **state machine as data**. A sub-status **clones** its `main_status`
(so the 2-value main status is derived, never trusted from the caller). `requires_approval` forces an
approval reference; `affects_provisioning` makes the change emit `CustomerAccountStatusChanged` for
FUL-03. An operator adds a state by adding a row — no code.

### `cvm_offer_instance` (`status`: `DRAFT|PROPOSED|PENDING_APPROVAL|ACCEPTED|APPLIED|REJECTED|EXPIRED|FAILED`) & `cvm_activity`
```json
{ "offer_instance_id":"cvo_1","customer_id":"CUS-1","offer_type":"RETENTION_DISCOUNT","discount_percent":10,"status":"APPLIED" }
{ "offer_instance_id":"cvo_2","customer_id":"CUS-1","offer_type":"RETENTION_DISCOUNT","discount_percent":25,"status":"PENDING_APPROVAL","approval_request_id":"appr_9" }
{ "activity_id":"cva_1","customer_id":"CUS-1","activity_type":"PAYMENT_RECOVERY","priority":"HIGH","status":"OPEN" }
{ "activity_id":"cva_2","customer_id":"CUS-3","activity_type":"UPSELL_OFFER","priority":"NORMAL","status":"OPEN" }
```
**Reading:** cvo_1 (10%, under threshold) applied straight through; cvo_2 (25%) is parked on EM-CFG-04
and can't be accepted until approved. Activities are the CVM worklist — a high-risk dunning signal opens
a `PAYMENT_RECOVERY` activity; a healthy account an `UPSELL_OFFER`.

## 3. Services
| Service | Responsibility |
| --- | --- |
| `CustomerService` | customer CRUD; `recordKycDecision()` (two-step, config authority) |
| `AccountService` | **only writer** of the account master — `update` (catalog-driven), `setFlag/clearFlag` (+ banner), `hasProvisioningBlockingFlag`/`hasDunningAccelerantFlag`, `routingContext()` |
| `CustomerOverviewService` | Customer 360 (degrades per panel) |
| `CvmEvaluationService` / `CvmFlagEvaluatorService` / `CvmActivityService` | signals→segments→activity; rules-driven flags; timeline |
| `CvmOfferService` | retention offers (EM-CFG-04 gated; resume; SIP-03 on accept) |

## 4. API surface
`/api/customers`, `…/accounts`, `…/kyc`, `…/flags`, `…/overview`, `/api/cvm/customers/{id}/evaluate`,
`/api/cvm-offers/{id}/accept`. `permission:customer.*`; KYC decisions config-gated.

## 5. Integration (events)
- **Topic `ilm.customer`:** `CustomerCreated/Updated`, `CustomerKyc{Approved,Rejected}`,
  `CustomerAccountCreated`, `CustomerAccountStatusChanged`, `CustomerAccountFlag{Set,Cleared}`.
- **Topic `em03.cvm`:** `CvmCustomerEvaluated`, `CvmActivity{Created,Closed}`, `CvmOffer{Proposed,
  Accepted,Applied}`.
- **Consumes:** `ResumeCvmOfferOnApproval`. **Downstream of ILM:** Provisioning, Notification, Fulfillment.

## 6. Processes
No BPMN; KYC + CVM offer governance via EM-CFG-04; `sophix:cvm:evaluate-flags` daily worker.

## 7. Policy & config
`rules.cvm.offer` + flag-evaluation rules; the flag/sub-status/KYC-authority catalogs — operator data.

## 8. Cross-module dependencies
- **Consumed by →** everyone (`routingContext`); flags steer BIL-04 + FUL-03; status change steers
  Provisioning + Notification; KYC gates Fulfillment.
- **Calls →** Foundation Approvals, Rules, Files; Catalog (`DiscountAssignment` on offer accept).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-ILM-S-2 | a `requires_approval` sub-status change needs an approval reference | `AccountService::update` |
| R-ILM-S-3 | `affects_provisioning` change emits `CustomerAccountStatusChanged` for FUL-03 | `AccountService` + listener |
| R-ILM-F-3/F-4 | `affects_dunning`/`affects_provisioning` flags steer BIL-04 / FUL-03 | `hasDunningAccelerantFlag`/`hasProvisioningBlockingFlag` |
| R-ILM-K-3 | KYC approval authority per level is operator config | `recordKycDecision` |

## 10. Open items / deltas
- `CustomerAccountFlagSet/Cleared` + `CustomerKycRejected` now have consumers (FUL-03 block, order
  cancel) — earlier orphans, fixed.
- Customer-facing surfacing of `customer_visible` flags (R-ILM-F-5) is data-modelled; a dedicated
  customer endpoint is optional.
