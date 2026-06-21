# ILM — As-Built Design (Customer & CVM)

> **Capability codes:** ILM-CFG-01 (customer/account/360/KYC), EM-03 (CVM retention) · **Module
> path:** `Modules/Ilm` · **Source-of-truth tests:** `Modules/Ilm/tests/Feature/*`
> (CustomerApi, CustomerOverview, AccountFlag, Cvm, CvmFlagEvaluator)

## 1. Purpose & boundaries
- **Owns:** the **customer** and **customer-account** masters, the KYC chain, the account-flag &
  sub-status catalogs, the customer interaction timeline, and the **CVM** engine (segments, activities,
  retention offers).
- **Does NOT own:** subscriptions, money, equipment. It is the **system of record for "who the customer
  is and what state their account is in,"** and the source of the read-only routing context other
  modules feed into their rules.
- **Job:** authoritative customer/account data + a data-driven flag/sub-status model + governed CVM.

## 📖 Scenarios — read these first

### Scenario A — risk team flags an account "No Payment Done"
1. **Request:** `PUT /api/customer-accounts/acc_9/flags/NPD`
2. `AccountService::setFlag`: the flag must be in the operator's `customer_account_flag_catalog`
   (NPD is, with `surfaces_attention=true`, `affects_dunning=true`). Writes a
   `customer_account_flag` row + sets `attention_banner` ("No Payment Done"). Emits
   `CustomerAccountFlagSet`.
3. **Cross-module effect:** next time BIL-04 dunning scans this account,
   `AccountService::hasDunningAccelerantFlag('acc_9')` is true → the dunning **grace window is
   waived** (R-ILM-F-3), so it escalates a level immediately instead of waiting.
4. Clearing the flag (`clearFlag`) recomputes the banner from the remaining active flags.
- **Proven by:** `AccountFlagTest`, `DunningTest::test_dunning_accelerant_flag_waives_grace`.

### Scenario B — a retention offer that needs sign-off
1. `CvmOfferService::propose` for a 25% retention discount. `rules.cvm.offer` says this exceeds the
   threshold → `requireApproval` → the offer parks **`PENDING_APPROVAL`** with an EM-CFG-04 request.
2. `accept()` on it returns **409** (can't accept a pending offer).
3. A supervisor approves ⇒ `ApprovalApproved` ⇒ `ResumeCvmOfferOnApproval` →
   `applyApprovalOutcome` releases the offer to `PROPOSED`; now `accept()` works and calls SIP-03
   (creates a `DiscountAssignment`) + records a `cvm_outcome`.
- **Proven by:** `CvmTest::test_granting_the_approval_resumes_the_offer_so_it_can_be_accepted`.

## 2. Data model (selected)
| Table | Purpose | Invariants |
| --- | --- | --- |
| `customer` | the person/org master (+ `tax_identifier`, KYC status) | unguarded; operator-scoped |
| `customer_account` | the account master: `status` (ACTIVE/INACTIVE), `sub_status`, `service_class_1..3`, `attention_banner` | one writer (`AccountService`); status history append-only |
| `customer_account_flag` (+ `…_catalog`) | operator-extensible flags (NPD, FRAUD_SUSPECTED, …) with `surfaces_attention` / `affects_dunning` / `affects_provisioning` / `customer_visible` | catalog-gated; flags drive cross-module behaviour |
| `customer_sub_status_catalog` | data-driven sub-status registry: `main_status` clone, `requires_approval`, `affects_provisioning`, `customer_visible` | the account "state machine" config |
| `kyc_approval` / `customer_kyc_document` | two-level KYC chain | level authority is config (`kyc_approval_role`) |
| `customer_interaction` / `customer_note` / `contact_method` | CUST-INT-01 timeline | |
| `cvm_signal_profile` / `cvm_segment_membership` / `cvm_activity` / `cvm_offer_instance` / `cvm_outcome` | EM-03 CVM | offer over threshold → EM-CFG-04 |

## 3. Services & responsibilities
| Service | Responsibility |
| --- | --- |
| `CustomerService` | customer CRUD; **`recordKycDecision()`** two-step KYC (config authority); emits `CustomerKyc{Approved,Rejected}` |
| `AccountService` | the only writer of the account master — `create/update` (sub-status catalog drives the change), `setFlag/clearFlag` (+ `recomputeAttentionBanner`), `hasProvisioningBlockingFlag` (R-ILM-F-4), `hasDunningAccelerantFlag` (R-ILM-F-3), **`routingContext()`** (read-only fact set for any module's rules) |
| `CustomerOverviewService` | Customer 360 aggregation (degrades per-panel) |
| `CvmEvaluationService` | signals → churn score → segments → activity (idempotent by source event) |
| `CvmFlagEvaluatorService` | rules-driven flag raising |
| `CvmActivityService` | activities + interaction timeline writes |
| `CvmOfferService` | retention offers; **EM-CFG-04** gated; `applyApprovalOutcome` resumes a parked offer; accept → SIP-03 discount assignment + outcome |

## 4. API surface
`/api/customers`, `…/accounts`, `…/kyc`, `…/flags`, `…/overview`, `/api/cvm/customers/{id}/evaluate`,
`/api/cvm-offers/{id}/accept`. Writes `permission:customer.*`; KYC decisions config-gated.

## 5. Integration (events)
- **Topic `ilm.customer`:** `CustomerCreated/Updated`, `CustomerKyc{Approved,Rejected}`,
  `CustomerAccountCreated`, **`CustomerAccountStatusChanged`** (carries `affectsProvisioning` +
  `customerVisible`), `CustomerAccountFlag{Set,Cleared}`.
- **Topic `em03.cvm`:** `CvmCustomerEvaluated`, `CvmActivity{Created,Closed}`,
  `CvmOffer{Proposed,Accepted,Applied}`.
- **Consumes:** `ResumeCvmOfferOnApproval` (platform.approvals → resume a PENDING_APPROVAL offer).
- **Downstream of ILM events:** Provisioning (`SyncProvisioningOnAccountStatusChanged`), Notification
  (`AccountStatusNotificationBridge`), Fulfillment (`ResumeOrderOnKycApproved` /
  `CancelOrderOnKycRejected`).

## 6. Processes
No BPMN; service-level governance (KYC maker-checker, CVM offer EM-CFG-04). The daily
`sophix:cvm:evaluate-flags` worker runs the flag evaluator.

## 7. Policy & config
- `rules.cvm.offer` (offer threshold → requireApproval), CVM flag-evaluation rules.
- The flag catalog, sub-status catalog, KYC authority roles — all per-operator data.

## 8. Cross-module dependencies
- **Consumed by →** everyone: `AccountService::routingContext()` is the shared fact set; flags steer
  BIL-04 (dunning) and FUL-03 (activation block); `CustomerAccountStatusChanged` steers Provisioning +
  Notification; KYC events gate Fulfillment activation.
- **Calls →** Foundation Approvals (KYC/CVM), Rules; Catalog (`DiscountAssignment` on offer accept).

## 9. Invariants & rules (examples)
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-ILM-S-2 | a `requires_approval` sub-status change must carry an approval reference | `AccountService::update` |
| R-ILM-S-3 | `affects_provisioning` sub-status change emits `CustomerAccountStatusChanged` for FUL-03 | `AccountService` + Provisioning listener |
| R-ILM-F-3/F-4 | `affects_dunning`/`affects_provisioning` flags steer BIL-04 / FUL-03 | `hasDunningAccelerantFlag` / `hasProvisioningBlockingFlag` |
| R-ILM-K-3 | KYC approval authority per level is operator config | `recordKycDecision` |

## 10. Open items / deltas
- `CustomerAccountFlagSet/Cleared` and `CustomerKycRejected` now have consumers (FUL-03 block, order
  cancel) — earlier orphans, fixed.
- Customer-facing surfacing of `customer_visible` flags (R-ILM-F-5) is data-modelled; a dedicated
  customer-facing endpoint is optional.
