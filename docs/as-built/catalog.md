# Catalog — As-Built Design

> **Capability codes:** PLM-CFG-01..07 (products/services/wallets/tax/voice), SIP-02 (package
> launch), SIP-DA / PLM-CFG-04 (discounts), RLM-CFG-01 (HomePass) · **Module path:** `Modules/Catalog`
> **Source-of-truth tests:** `Modules/Catalog/tests/Feature/*` (Catalog, Tax{Compute,Config},
> Discount{Compute,Assignment}, Bundle/Campaign, PackageLaunch, VoiceTariff, WalletCatalog, UsageRating)

## 1. Purpose & boundaries
- **Owns:** the **reference/config substrate** the rest of the platform prices and sells against —
  packages & versions, services, bundles, voice/usage tariffs, **discounts & promotions**, **tax
  groups/rules**, wallet catalog, HomePass topology + tech regions, network nodes.
- **Does NOT own:** money movement (Billing), subscriptions (Subscription), the network (Provisioning).
  It is **read-mostly config**; other modules query it at decision time.
- **Job:** be the single, operator-scoped, governed source of "what can be sold, at what price, with
  what tax/discount, where" — tunable as data, with maker-checker on the risky launches.

## 2. Data model (selected)
| Table | Purpose | Key invariants |
| --- | --- | --- |
| `package` / `package_version` / `package_service` | sellable product + priced versions; the **package** is priced (`package_version.price`), components are not | versioned; `PackageVersion` carries the price proration reads |
| `service` / `service_class` | components: `consumption_model` (CYCLICAL/USAGE), `revenue_category`, `default_wallet_ref` | usage routing keyed by `revenue_category` |
| `commercial_bundle` (+ components/rules) | multi-package bundles, migration & discount rules | launch maker-checker |
| `discount` / `discount_assignment` (+ history) | the discount engine: catalog + per-scope grants | grants gated by EM-CFG-04; DIRECT > CAMPAIGN on tie |
| `promo_campaign` (+ offer/target/channel/participation) | campaign engine | window-gated at billing time |
| `tax_group` / `tax_rule` | the tax cascade (`BASE` / `BASE_PLUS_PRIOR`), `NONE`=exempt | resolved by `rules.tax-applicability` |
| `usage_tariff` / `voice_tariff` (+ destination zones/prefixes) | metered rating tables | longest-prefix match for voice |
| `homepass` / `homepass_status_code` / `homepass_tech_region` | premises topology + sellability lifecycle | status transitions via approval |
| `tech_region` / `network_node` | geography + plant | |

## 3. Services & responsibilities
| Service | Responsibility |
| --- | --- |
| `CatalogService` | packages / services / versions CRUD + lifecycle |
| `PackageLaunchService` | SIP-02 maker-checker launch plan (gated by EM-CFG-04; resumed by `ApplyPackageLaunchApproval`) |
| `BundleService` / `CampaignService` | bundles + promotions |
| `DiscountAssignmentService` | grant a discount to a scope (dup-block, EM-CFG-04 routing, DIRECT/CAMPAIGN mode) |
| `DiscountComputeService` | resolve the effective discount(s) for a context (stacking, priority) |
| `TaxComputeService` | **`compute()`** — resolve tax via `rules.tax-applicability`, iterate the group's rules, cascade `BASE`/`BASE_PLUS_PRIOR`; `NONE`=exempt; product `default_tax_group_ref` fallback |
| `TaxConfigService` | tax group/rule config |
| `VoiceTariffService` / `UsageRatingService` | rate metered events against the tariff tables |
| `WalletCatalogService` | wallet-type catalog (PLM-CFG-03) consumed by Billing wallets |
| `HomePassTopologyService` / `NetworkCatalogService` / `TechCoverageService` | premises, plant, coverage |

## 4. API surface
Reference CRUD under `/api/` (packages, services, bundles, discounts, campaigns, tax-groups/rules,
voice-tariffs, wallets, homepass, tech-regions). Writes guarded by `permission:catalog.manage`
(reads `catalog.read`); risky launches (package/bundle) go through an approve/decide pair.
*(Fill the exact route table when expanding this doc — see `_TEMPLATE.md` §4.)*

## 5. Integration (events) — topic `catalog.reference`
- **Emits:** package/service/version lifecycle (`PackageCreated`, `PackageVersionAdded`,
  `PackageActivated`, `PackageEndOfSale`, `PackageRetired`), `HomePassStatusChanged` /
  `HomePassReachedSellable`, discount-assignment lifecycle (`DiscountAssignment{Created,Activated,
  Cancelled,Expired,Rejected,ApprovalRequired}`), wallet, bundle, campaign, **package-launch**
  (`PackageLaunch{PlanCreated,Validated,ApprovalRequired,Approved,Rejected}`), voice-tariff.
- **Consumes (`platform.approvals`):** `ApplyHomePassTransitionOnApproval`, `ApplyPackageLaunchApproval`
  resume their maker-checker flows on `ApprovalApproved/Rejected`.
- **Cache:** `CatalogCacheInvalidator` evicts read-model snapshots on catalog changes (Billing mirrors
  PLM wallet/tax config and evicts via `Billing\Listeners\EvictPlmCatalogCache`).

## 6. Processes (workflow)
Mostly **service-level governance** (approval-gated launches), not BPMN flows. Package/bundle launch
and HomePass status transitions are the maker-checker paths (EM-CFG-04 request → resume listener).

## 7. Policy & config (no-code knobs)
- **`rules.tax-applicability`** decision table — what's taxed, exemptions, group selection.
- Every catalog table **is** the config: prices/versions, discount & campaign rules, tariff tables,
  tax groups/rules, wallet types, HomePass status model, tech regions. Wananchi = re-seed these.

## 8. Cross-module dependencies
- **Consumed by →** Billing (`ChargeComputeService` reads `package`/`package_service`/`service`;
  `TaxComputeService` for tax; **discount engine** for promos), Subscription (package refs),
  Fulfillment (package), Provisioning/WorkOrder (tech region, HomePass).
- **Calls →** Foundation Approvals (launch/discount/HomePass maker-checker), Rules.

## 9. Invariants & rules (examples)
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-PLM-02-AP-2/3 | explicit `NONE` tax group = exempt (no fallback); else rule group then product default | `TaxComputeService::compute` |
| R-SIP-DA-05 | block a duplicate active grant for the same discount + scope + window | `DiscountAssignmentService` |
| R-SIP-DA-07/11 | high-value/long/manual grants route through EM-CFG-04 | `DiscountAssignmentService` |
| SIP-02 R-05 | package launch is maker-checker | `PackageLaunchService` + `ApplyPackageLaunchApproval` |

## 10. Open items / deltas
- **Discount → invoice line:** the discount engine computes/grants (wallet/credit/invoice targets),
  but Billing's recurring run does **not** auto-insert a discount line at cycle close (see
  `billing.md` §10). Wiring that generic stage is the one optional revenue-path enhancement.
- `TechContractorSkill` / `TechRegionContractor` here vs Workforce's `skill_catalog` are **intentionally
  distinct** (contractor config vs EM-02 capacity), not duplication.
