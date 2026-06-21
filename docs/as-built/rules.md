# Rules — As-Built Design (decision engine)

> **Module path:** `Modules/Rules` + `app/Foundation/Rules` · **Source-of-truth tests:**
> `DecisionTableTest`, `DroolsRuleEngineTest` · **See also `00_SPINE.md` §6.**

## 1. Purpose & boundaries
- **Owns:** the **decision engine** (FOUNDATION_DROOLS): operator-overridable **decision tables**
  evaluated by a rule package name, plus a registered **code-fallback** when no table is deployed.
- **Does NOT own:** the facts (each caller supplies them) or the action (the caller applies the
  decision). It only **decides**.
- **Job:** keep operator-varying policy out of `if` statements — branch through a named rules package.

## 📖 Scenarios — read these first

### Scenario A — "how much tax applies?" as data
1. Billing/Catalog calls `RuleEngine::evaluate('rules.tax-applicability', {taxableKind:'PACKAGE',
   customerCategory:'RES', …})`.
2. If a `decision_table` for that package is deployed, its rows are matched against the facts and the
   first/priority match returns the outcome (e.g. tax group `KE_VAT`). If none is deployed, a
   **registered fallback** computes the same answer from config.
3. The caller acts on `{ruleGroup, …}` — Wananchi changes the table rows, not the code.
- **Proven by:** `DecisionTableTest`, `DroolsRuleEngineTest`, and each consumer's test (tax, dunning,
  CVM offer, adjustment-approval).

### Scenario B — author a table in the Studio
- `POST /api/rules/decision-tables` (rows of conditions→outcome), then `POST /api/rules/{ruleSet}/evaluate`
  to test it against sample facts before consumers use it.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| `decision_table` | rows of input conditions → output for a rule package | operator-scoped, versioned |

## 3. Engine
| Component | Responsibility |
| --- | --- |
| `Foundation/Rules/RuleEngine` | `evaluate('rules.<pkg>', $facts)` → decision; `register(pkg, fn)` for code fallbacks |

## 4. API surface
`/api/rules/decision-tables[/{id}]` (CRUD/Studio), `/api/rules/{ruleSet}/evaluate` (test). Guarded by
`permission:rules.*`.

## 5. Integration
- **Consumed by →** Billing (`rules.billing.adjustment-approval`), Catalog (`rules.tax-applicability`),
  ILM (`rules.cvm.offer`, flag eval), WorkOrder (`rules.field_audit.*`), and more.

## 6. Processes
Synchronous evaluation; no workflow.

## 7. Policy & config
The decision tables **are** the config; a registered fallback guarantees a deterministic answer when
no table is deployed (so the platform runs out-of-the-box and operators tune later).

## 8. Cross-module dependencies
- **Used by →** most domains for policy branching.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| table-or-fallback | a package always resolves (deployed table else registered fallback) | `RuleEngine::evaluate` |
| operator override | an operator's table overrides the default | decision-table scope |

## 10. Open items / deltas
- New policy = a new rule package + (optionally) a decision table; the engine driver is env-selectable
  (`SOPHIX_RULES_DRIVER`).
