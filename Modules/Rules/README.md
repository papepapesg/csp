# Rules

Data-driven decision capability for versioned decision tables, deployment, evaluation, explanations and operator policy variation.

## Use

Manage tables through `routes/api.php`, evaluate them through `RuleEngine`, and inspect deployed policy with `sophix:rules:*` commands. Callers provide facts and consume typed decisions.

## Configure

Inputs, ordered rules, outputs, versions and defaults are configuration. Only approved executable outputs may select registered system behavior.

## Extend

Add facts or outputs without embedding module models in the engine. A new execution backend implements `RuleEngine`; preserve deterministic results and explanation metadata, then add parity/contract tests.

## Exposed APIs

- `GET rules/decision-tables`
- `GET rules/decision-tables/{decisionTable}`
- `POST rules/decision-tables`
- `POST rules/{ruleSet}/evaluate`
- `PUT rules/decision-tables/{decisionTable}`

## Data models

- `DecisionTable`

## Services

- `DataDrivenRuleEngine`
- `DroolsRuleEngine`

## Events

- No module-specific event catalog or listener is currently registered.

## Commands

- `sophix:rules:evaluate`
- `sophix:rules:ops-status`
- `sophix:rules:table-show`

## Test

Decision-table API and runtime scenarios are in `tests/Feature`.
