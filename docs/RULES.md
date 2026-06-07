# SOPHIX Rule Packages (FOUNDATION_DROOLS)

Rules are a **cross-cutting policy layer**, not an activation feature. The design
names every rule package `rules.<domain>.<kind>` (operator scoping via the
`operator_code` column = the design's `.<operator>` suffix; container analogue
`{module}-rules-{operator}_{version}`). Every result carries a stable **ruleId**
(`ValidationError{ruleId,field,message}` / `DecisionResult{decisionCode,ruleId,attributes}`,
DROOLS-RES-1); empty results mean "pass". Operators override any package in the
**Rules Studio** (`/rules/studio`) with no code change.

Engine: `Modules/Rules/app/Engine/DataDrivenRuleEngine.php` (bound to
`App\Foundation\Rules\RuleEngine`). Packages are `decision_table` rows.

## Status — packages the corpus names (grep `rules\.` over docs/design-text)

| Rule package (design) | Domain / DD | Status |
| --------------------- | ----------- | ------ |
| `rules.subscription.activate` | SUB-WF-ACTIVATE-01 preconditions/eligibility | ✅ seeded + wired (activation gateway) |
| `rules.subscription.terminate` | SUB-WF-TERMINATE-01 | ✅ seeded |
| `rules.service-catalog` | PLM-CFG-01 service config | ✅ seeded + wired (service create) |
| `rules.homepass-catalog` | RLM-CFG-01 HomePass config | ✅ seeded + wired (HomePass create) |
| `rules.subscription.pause` / `.resume` / `.suspend-np` | SUB-WF-PAUSE/RESUME-01, SUB-WF-SUSPEND-NP-01 | ✅ seeded + wired (validate-operation gateway) |
| `rules.subscription.restrict` | SUB-WF-RESTRICT-01 (R-RG-2 policy layer) | ✅ seeded + wired (`sub-restrict` flow; catalog/state/dunning gating in RestrictionService) |
| `rules.billing.dunning` | BIL-04 escalation policy | ✅ seeded + wired (DunningService) |
| `rules.workorder.site-visit-decision` / `.resolution-gate` | WO-01-FLOW-SUPPORT gateways | ✅ seeded + wired (wo-support flow) |
| `rules.osr.swap.eligibility` / `rules.osr.recovered-routing` | OSR-RMA-01 swap eligibility + recovered-instance routing fix | ✅ seeded + wired (osr-swap flow) |
| `rules.subscription.upgrade` / `.downgrade` | SUB-WF-UPGRADE/DOWNGRADE-01 target-package validation | ✅ seeded + wired (sub-upgrade/sub-downgrade flows) |
| `rules.subscription.relocation` / `.migration` | SUB-WF-RELOCATION/MIGRATION-01 target-HomePass validation | ✅ seeded + wired (sub-relocation/sub-migration flows) |
| `rules.subscription.common` | shared subscription policy | ⬜ |
| `rules.tax` | PLM-CFG-02 / BIL tax applicability | ⬜ (tax fiscalisation stub exists) |
| `rules.wallet` / `rules.wallet-catalog` | BIL-05 / PLM-CFG-03 | ⬜ |
| `rules.discount-catalog` / `rules.commercial.discount_assignment` / `.bundle` / `.campaign` | PLM-CFG-04 / SIP / commercial (Wave 3) | ⬜ |
| `rules.cvm.offer` | EM-03 CVM offer resolution | ✅ seeded + wired (CvmService) |
| `rules.field_audit.{equipment,network,kyc}.severity` | FA-01/02/03 field audits | ✅ seeded + wired (FieldAuditService) |
| `rules.fulfillment.technology_migration.{eligibility,cutover,equipment,exception}` | FUL-10 (Wave 3) | ⬜ |
| `rules.asr.routing` | ASR-01..04 intake routing | ✅ seeded + wired (AsrService) |
| `rules.service-catalog` / `rules.homepass-status-code-catalog` / `rules.house-type-catalog` / `rules.network-node-catalog` / `rules.tech-region-catalog` / `rules.tech-contractor-catalog` / `rules.tech-contractor-skill-catalog` / `rules.franchise-catalog` | catalog config validation | 🚧 service + homepass done; rest seed with their catalogs |
| `rules.user` / `rules.password` | FOUNDATION_AUTH | ⬜ |

The ⬜ packages belong to capabilities not yet built (Wave 2/3); each is seeded +
wired at its decision point when that flow lands, using this same convention.
| `rules.tax-applicability` | PLM-CFG-02 tax group resolution | ✅ seeded + wired (TaxComputeService) |
