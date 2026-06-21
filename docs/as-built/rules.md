# Rules — As-Built Design (decision engine)

> **Module path:** `Modules/Rules` + `app/Foundation/Rules` · **Tests:** `DecisionTableTest`,
> `DroolsRuleEngineTest` · **See `00_SPINE.md` §6.**

## 1. Purpose & boundaries
- **Owns:** the **decision engine** (FOUNDATION_DROOLS): operator-overridable **decision tables**
  evaluated by a package name, plus a registered **code-fallback** when no table is deployed.
- **Does NOT own:** the facts (the caller supplies) or the action (the caller applies). It only decides.
- **Job:** keep operator-varying policy out of `if`s — branch through a named rules package.

## 📖 Scenarios (service + Foundation involvement)

### 1. "How much tax applies?" as data
Catalog `TaxComputeService` calls `RuleEngine::evaluate('rules.tax-applicability', {taxableKind,
customerCategory})` → a deployed `decision_table`'s rows match the facts → returns the tax group.
*Proven by consumers' tests + `DecisionTableTest`.*

### 2. Fallback when no table is deployed
`rules.billing.adjustment-approval` with no table → the registered fallback derives `stepsRequired` from
`adjustment_limits_config`. *Foundation: deterministic answer out-of-the-box.* *Proven by `AdjustmentTest`.*

### 3. Author a table in the Studio
`POST /api/rules/decision-tables` (rows of conditions→outcome) → `POST /api/rules/{ruleSet}/evaluate`
tests it against sample facts before consumers use it.

### 4. Operator override
A WIK-scoped table for a package overrides the platform default for that operator. *Shows: per-operator
policy without code.*

### 5. CVM offer threshold
`rules.cvm.offer({offerType, discountPercent})` → `{requireApproval, approvalPolicy}` drives EM-CFG-04.
*Proven by `CvmTest`.*

### 6. Field-audit discrepancy routing
`rules.field_audit.equipment.discrepancy({discrepancyType})` → `{severity, routeAction}`. *Proven by
`FieldAuditCampaignTest`.*

### 7. Dunning routing knobs
Dunning grace/levels come from the `dunning_program` (catalog), but rule packages can refine routing —
the same evaluate seam.

### 8. Test-evaluate endpoint
`POST /api/rules/{ruleSet}/evaluate {facts}` returns the decision — used to validate a table before
deploy.

## 2. Data model — ≥4 sample rows + readings

### `decision_table` (rows of input conditions → output, per package)
```json
{ "id":"dt_1","rule_set":"rules.tax-applicability","operator_code":"WIK","conditions":{"taxableKind":"PACKAGE","customerCategory":"RES"},"outcome":{"taxGroup":"KE_INTERNET"} }
{ "id":"dt_2","rule_set":"rules.tax-applicability","operator_code":"WIK","conditions":{"taxableKind":"USAGE","serviceCategory":"VOICE"},"outcome":{"taxGroup":"KE_VOICE"} }
{ "id":"dt_3","rule_set":"rules.tax-applicability","operator_code":"WIK","conditions":{"taxableKind":"USAGE","serviceCategory":"DATA"},"outcome":{"taxGroup":"NONE"} }
{ "id":"dt_4","rule_set":"rules.cvm.offer","operator_code":"WIK","conditions":{"discountPercent":{">=":20}},"outcome":{"requireApproval":true,"approvalPolicy":"CVM_HIGH_VALUE"} }
```
**Reading:** each row is a (conditions → outcome) for a **rule package**. dt_3's `taxGroup:NONE` makes
data usage **tax-exempt** (an enum value that inverts behaviour). dt_4 says discounts ≥ 20% need
approval. Rows are operator-scoped, so a WIK table overrides the default. Evaluate returns the matching
outcome (or the registered fallback if no table).

## 3. Engine
| Component | Responsibility |
| --- | --- |
| `Foundation/Rules/RuleEngine` | `evaluate('rules.<pkg>', $facts)` → decision; `register(pkg, fn)` fallbacks |

## 4. API surface
`/api/rules/decision-tables[/{id}]` (CRUD/Studio), `/api/rules/{ruleSet}/evaluate` (test).
`permission:rules.*`.

## 5. Integration
- **Consumed by →** Billing (`adjustment-approval`), Catalog (`tax-applicability`), ILM (`cvm.offer`,
  flag eval), WorkOrder (`field_audit.*`), and more.

## 6. Processes
Synchronous evaluation; no workflow.

## 7. Policy & config
The decision tables **are** the config; a registered fallback guarantees a deterministic answer when no
table is deployed; `SOPHIX_RULES_DRIVER` selects the engine.

## 8. Cross-module dependencies
- **Used by →** most domains for policy branching.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| table-or-fallback | a package always resolves | `RuleEngine::evaluate` |
| operator override | an operator's table overrides the default | decision-table scope |

## 10. Open items / deltas
- New policy = a new package + (optionally) a table; the engine driver is env-selectable.
