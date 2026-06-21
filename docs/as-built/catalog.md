# Catalog — As-Built Design (the config substrate)

> **Capability codes:** PLM-CFG-01..07 (products/services/wallets/tax/voice), SIP-01/02 (bundles/
> launch), PLM-CFG-04/SIP-DA (discounts), RLM-CFG-01 (HomePass) · **Module path:** `Modules/Catalog`
> **Tests:** `CatalogApi`, `ConfigCatalog`, `Tax{Compute,Config}`, `Discount{Compute,Assignment}`,
> `BundleAndCampaign`, `PackageLaunch`, `VoiceTariff`, `WalletCatalog`, `UsageRating`

## 1. Purpose & boundaries
- **Owns:** the **reference/config substrate** everything prices and sells against — packages &
  versions, services, bundles, voice/usage tariffs, **discounts & promotions**, **tax groups/rules**,
  wallet catalog, HomePass topology + tech regions, network nodes.
- **Does NOT own:** money (Billing), subscriptions (Subscription), the network (Provisioning). It is
  **read-mostly config**; other modules query it at decision time.
- **Job:** the single, operator-scoped, governed source of "what can be sold, at what price, with what
  tax/discount, where" — tunable as **data**, with maker-checker on risky launches.

## 📖 Scenarios (service + Foundation involvement)

### 1. Launch a package (SIP-02 maker-checker)
`CatalogService` creates a `package` (`DRAFT`) + a priced `package_version`. `PackageLaunchService`
opens a `PackageLaunchPlan`; risky launch → **EM-CFG-04** request (`Foundation/Approvals`),
`PACKAGE_LAUNCH_APPROVAL_REQUIRED`. A different approver approves → `ApplyPackageLaunchApproval`
(listener) activates the package (`PACKAGE_ACTIVATED`). *Proven by `PackageLaunchTest`.*

### 2. Tax on a 5,000 KES internet package
Billing calls `TaxComputeService::compute({taxableKind:'PACKAGE', customerCategory:'RES', baseAmount:5000})`.
It evaluates **`rules.tax-applicability`** (`Foundation/Rules`) → a `tax_group`; iterates its `tax_rule`
rows by `order_within_group`, computing each per `base_method` (`BASE`/`BASE_PLUS_PRIOR` cascade);
`NONE` ⇒ exempt. Returns `{subtotal, taxTotal, taxLines}`. *Proven by `TaxComputeTest`.*

### 3. Grant a discount (EM-CFG-04 if high-value)
`DiscountAssignmentService::assign` blocks a duplicate active grant (R-SIP-DA-05), then asks EM-CFG-04
if approval is needed (R-SIP-DA-07/11). High value → `discount_assignment.status=PENDING_APPROVAL`
(`DiscountAssignmentApprovalRequired`); approval → `ACTIVATED`. *Proven by `DiscountAssignmentTest`.*

### 4. Resolve effective discount(s) at billing time (stacking)
`DiscountComputeService::resolve(context)` finds the applicable assignments, applies stacking +
priority (DIRECT beats CAMPAIGN on a tie), and returns the effective discount. *Proven by
`DiscountComputeTest`.*

### 5. Bundle launch (maker-checker)
`BundleService` walks a `commercial_bundle` `DRAFT → READY_FOR_REVIEW → APPROVED → ACTIVE` with launch
checks; approval gated. *Proven by `BundleAndCampaignTest`.*

### 6. Voice rating (longest-prefix match)
`VoiceTariffService` rates a call by matching the dialled number against `voice_destination_prefix`
(longest prefix wins) → its `voice_destination_zone` rate. `UsageRatingService` does the same for data/
SMS tariffs. *Proven by `VoiceTariffTest`, `UsageRatingTest`.*

### 7. HomePass becomes sellable (approval-gated transition)
`HomePassTopologyService` transitions a `homepass` `UNDER_CONSTRUCTION → SELLABLE`; an EM-CFG-04 gate
(RLM-CFG-01 H-5) → `ApplyHomePassTransitionOnApproval` applies it → `HomePassReachedSellable`
(Fulfillment can now take orders for it). *Proven by `ConfigCatalogTest`.*

### 8. Wallet catalog feeds Billing
`WalletCatalogService` defines `wallet_type` rows (`allow_negative`, `auto_debit`); Billing creates
prepaid `wallet`s of those types and routes usage by `wallet_type_code`. *Cross-module config handoff.*

### (bonus) 9. Any catalog change evicts stale read-models
`CatalogCacheInvalidator` (listener) + Billing's `EvictPlmCatalogCache` drop cached snapshots on
catalog lifecycle events (`Foundation/Cache`).

## 2. Data model — ≥4 sample rows + readings

### `package` (`status`: `DRAFT|ACTIVE|END_OF_SALE|RETIRED`) & `package_version`
```json
{ "id":"pkg_triple","code":"TRIPLE_PLAY","status":"ACTIVE" }
{ "id":"pkg_inet","code":"INET_100","status":"ACTIVE" }
{ "id":"pkg_promo","code":"BLACK_FRIDAY","status":"DRAFT" }
{ "id":"pkg_old","code":"INET_50","status":"END_OF_SALE" }
// version (the priced thing proration reads)
{ "package_version_id":"pv_1","package_id":"pkg_triple","price":5000,"currency":"KES","status":"ACTIVE","effective_from":"2026-01-01" }
```
**Reading:** the **package** is priced via its **version** (`pv_1.price`), not its components. `DRAFT`
isn't sellable; `END_OF_SALE` keeps existing subs but takes no new orders; `RETIRED` is gone. A price
change = a new `package_version` (history preserved).

### `service` (`consumption_model`: `FLAT|USAGE`)
```json
{ "id":"svc_inet","code":"INTERNET","consumption_model":"FLAT","revenue_category":"INTERNET","provisioner_key":"gpon_inet","network_profile_shape":{"speed":"100M","vlan":101},"is_addressable":true }
{ "id":"svc_tv","code":"TV","consumption_model":"FLAT","revenue_category":"TV","provisioner_key":"iptv","network_profile_shape":{"bouquet":"PREMIUM"} }
{ "id":"svc_voice","code":"VOICE","consumption_model":"USAGE","revenue_category":"VOICE","provisioner_key":"sip","default_wallet_ref":"VOICE_WALLET" }
{ "id":"svc_data","code":"DATA","consumption_model":"USAGE","revenue_category":"DATA","default_wallet_ref":"DATA_WALLET" }
```
**Reading:** `consumption_model` splits **FLAT** (billed as the package fee) vs **USAGE** (metered →
rated events → its own charge, routed to `default_wallet_ref`). `network_profile_shape` +
`provisioner_key` are exactly what Provisioning puts in a command's `desired_profile`. `revenue_category`
is how Billing groups usage and how voice lands on its own invoice.

### `discount` (`discount_type`: `PERCENT|FIXED`) & `discount_assignment` (`scope`: `CUSTOMER|SUBSCRIPTION|PACKAGE|CAMPAIGN|ALL`)
```json
{ "code":"RET_25","discount_type":"PERCENT","value":25,"stackable":false,"status":"ACTIVE" }
{ "code":"WELCOME_500","discount_type":"FIXED","value":500,"stackable":true,"status":"ACTIVE" }
// assignments (status: ACTIVE|PENDING_APPROVAL|CANCELLED|EXPIRED|REJECTED ; mode DIRECT|CAMPAIGN)
{ "assignment_id":"dasg_1","discount_code":"RET_25","scope":"CUSTOMER","scope_ref":"cust_1","status":"ACTIVE","mode":"DIRECT" }
{ "assignment_id":"dasg_2","discount_code":"WELCOME_500","scope":"CAMPAIGN","campaign_code":"Q3_ACQ","status":"PENDING_APPROVAL","mode":"CAMPAIGN" }
```
**Reading:** the **discount** is the rule (25% or KES 500); the **assignment** is a grant to a scope.
`stackable` decides whether two can combine. dasg_1 is a manual (DIRECT) customer grant, live now;
dasg_2 is a campaign grant awaiting EM-CFG-04. *(Delta: grants reach the invoice via Billing's
adjustment/credit path, not an auto cycle-close line — see `billing.md` §10.)*

### `tax_group` & `tax_rule` (`base_method`: `BASE|BASE_PLUS_PRIOR`)
```json
{ "code":"KE_INTERNET","order_within_group":["WIK_INTERNET_VAT"] }
{ "code":"KE_VOICE","order_within_group":["WIK_EXCISE","WIK_VOICE_VAT"] }
// rules
{ "code":"WIK_INTERNET_VAT","taxable_category":"INTERNET","base_method":"BASE","rate":0.16 }
{ "code":"WIK_EXCISE","taxable_category":"VOICE","base_method":"BASE","rate":0.20 }
{ "code":"WIK_VOICE_VAT","taxable_category":"VOICE","base_method":"BASE_PLUS_PRIOR","rate":0.16 }
```
**Reading:** internet = one rule (16% VAT). Voice **cascades**: 20% excise on the base, then 16% VAT on
**base + excise** (`BASE_PLUS_PRIOR`) — Kenya's telecoms tax stack. `rules.tax-applicability` picks the
group per item; the rules run in `order_within_group`.

### `voice_tariff` (zones/prefixes) & `wallet_type` (catalog) & `homepass`
```json
{ "zone":"LOCAL","prefix":"+2547","rate_per_min":2.0 }
{ "zone":"INTL_UK","prefix":"+44","rate_per_min":15.0 }
// wallet_type (consumed by Billing) — allow_negative / auto_debit
{ "wallet_type_id":"wtyp_main","code":"MAIN_WALLET","allow_negative":false,"auto_debit":true }
// homepass (status: UNDER_CONSTRUCTION|SELLABLE|RETIRED)
{ "id":"hp_1","status":"SELLABLE","technology":"GPON","franchise_ref":"fr_nrb" }
```
**Reading:** voice rating does **longest-prefix** match (`+447…` → INTL_UK at 15/min). The wallet
**catalog** defines prepaid wallet behaviour Billing instantiates. Only `SELLABLE` homepasses accept
orders; `technology` picks the provisioning plane (see `provisioning.md`).

### `commercial_bundle` (`status`: `DRAFT|READY_FOR_REVIEW|APPROVED|ACTIVE|SUSPENDED|RETIRED|REJECTED|CANCELLED` · `bundle_type`: `ACQUISITION|RETENTION|MIGRATION|BUSINESS|STAFF|GENERAL`)
```json
{ "bundle_code":"TRIPLE_SAVER","status":"ACTIVE","bundle_type":"ACQUISITION","package_ref":"pkg_triple","channel_code":"SALES_APP" }
{ "bundle_code":"WINBACK","status":"READY_FOR_REVIEW","bundle_type":"RETENTION","package_ref":"pkg_inet" }
{ "bundle_code":"STAFF_PLAN","status":"ACTIVE","bundle_type":"STAFF","package_ref":"pkg_inet","channel_code":"BACKOFFICE" }
{ "bundle_code":"OLD_BIZ","status":"RETIRED","bundle_type":"BUSINESS","package_ref":"pkg_old" }
```
**Reading:** a bundle wraps a package for a purpose + channel; its status is a launch lifecycle (with
review/approval). `channel_code` limits where it's offered (SALES_APP vs BACKOFFICE vs USSD).

## 3. Services
| Service | Responsibility |
| --- | --- |
| `CatalogService` | packages/services/versions CRUD + lifecycle |
| `PackageLaunchService` | SIP-02 maker-checker launch (EM-CFG-04 + `ApplyPackageLaunchApproval`) |
| `BundleService` / `CampaignService` | bundles + promotions |
| `DiscountAssignmentService` / `DiscountComputeService` | grant (dup-block + EM-CFG-04) / resolve effective discount (stacking) |
| `TaxComputeService` / `TaxConfigService` | `compute()` via `rules.tax-applicability` cascade / tax config |
| `VoiceTariffService` / `UsageRatingService` | rate metered events (longest-prefix) |
| `WalletCatalogService` | wallet-type catalog (PLM-CFG-03) |
| `HomePassTopologyService` / `NetworkCatalogService` / `TechCoverageService` | premises, plant, coverage |

## 4. API surface
Reference CRUD under `/api/` (packages, services, bundles, discounts, campaigns, tax-groups/rules,
voice-tariffs, wallets, homepass, tech-regions). `permission:catalog.manage` (reads `catalog.read`);
package/bundle launch via an approve/decide pair.

## 5. Integration (events) — topic `catalog.reference`
- **Emits:** package/service/version lifecycle, `HomePassStatusChanged`/`…ReachedSellable`,
  `DiscountAssignment{Created,Activated,Cancelled,Expired,Rejected,ApprovalRequired}`, wallet, bundle,
  campaign, `PackageLaunch{…}`, voice-tariff.
- **Consumes (`platform.approvals`):** `ApplyHomePassTransitionOnApproval`, `ApplyPackageLaunchApproval`.
- **Cache:** `CatalogCacheInvalidator` (+ Billing's `EvictPlmCatalogCache`).

## 6. Processes
Service-level governance (approval-gated launches + HomePass transitions); no BPMN.

## 7. Policy & config
`rules.tax-applicability`; every catalog table **is** config (prices/versions, discounts/campaigns,
tariffs, tax groups/rules, wallet types, HomePass status model, tech regions). Wananchi = re-seed.

## 8. Cross-module dependencies
- **Consumed by →** Billing (charges/tax/discount), Subscription (package refs), Fulfillment (package),
  Provisioning/WorkOrder (tech region, HomePass, service profile).
- **Calls →** Foundation Approvals (launch/discount/HomePass), Rules, Cache.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-PLM-02-AP-2/3 | explicit `NONE` tax group = exempt; else rule group then product default | `TaxComputeService::compute` |
| R-SIP-DA-05 | block a duplicate active discount grant | `DiscountAssignmentService` |
| R-SIP-DA-07/11 | high-value/long/manual grants route through EM-CFG-04 | `DiscountAssignmentService` |
| SIP-02 R-05 | package launch is maker-checker | `PackageLaunchService` + listener |

## 10. Open items / deltas
- **Discount → invoice line:** the engine computes/grants (wallet/credit/invoice targets) but Billing's
  recurring run doesn't auto-insert a discount line (see `billing.md` §10). The one optional revenue
  enhancement.
- `TechContractorSkill`/`TechRegionContractor` here vs Workforce's `skill_catalog` are intentionally
  distinct (contractor config vs EM-02 capacity), not duplication.
