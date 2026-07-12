# Catalog Tax

Owns effective-dated tax rules, groups and deterministic tax computation used by billing and legal invoicing.

## Use

Tax catalog APIs are composed in `../Catalog/routes/api.php`. Configure and activate rules, then call the compute service with taxable lines and context. Inspect validity with `sophix:catalog-tax:ops-status`.

## Configure

Rates, compound ordering, applicability and effective windows are catalog/config data. Country authority signing and submission belong to `BillingTax` deployment adapters.

## Extend

Represent country variation as rules whenever existing computation semantics support it. Add code only for a genuinely new tax algorithm, selected by a registered behavior key and protected by examples and rounding tests.

## Exposed APIs

- `GET tax/groups`
- `GET tax/rules`
- `PATCH tax/groups/{taxGroup}`
- `PATCH tax/rules/{taxRule}`
- `POST tax/compute`
- `POST tax/groups`
- `POST tax/rules`

## Data models

- `TaxGroup`
- `TaxRule`

## Services

- `TaxComputeService`
- `TaxConfigService`

## Events

- `CatalogEvents::TAX_CONFIG_CHANGED`
- `CatalogEvents::TOPIC`

## Commands

- `sophix:catalog-tax:ops-status`

## Test

Tests live in `tests/Feature`; calculation scenarios also remain under `../Catalog/tests/Feature` during transition.
