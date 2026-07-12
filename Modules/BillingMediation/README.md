# Billing Mediation

Ingests usage records, normalizes and deduplicates them, resolves tariffs and allowances, and produces rated events for billing.

## Use

Submit usage through the mediation API or batch integration, then run rating workers/commands. Quarantined records require review rather than silent zero-rating.

## Configure

Source mappings and mediation policies are configuration. Rating catalogs are owned by `CatalogRating`; wallet allowances are owned by `BillingWallet`.

## Extend

Add source-specific parsers behind mediation contracts and keep the normalized record stable. New rating units require registered executable logic plus catalog entries and reconciliation tests.

## Exposed APIs

- `GET rated-events`
- `GET usage`
- `POST usage`
- `POST usage/rate-run`

## Data models

- `RatedEvent`
- `UsageRecord`

## Services

- `MediationRatingService`

## Events

- No module-specific event catalog or listener is currently registered.

## Commands

- `sophix:billing:rate-usage`

## Test

Allowance, rating and usage-tariff tests are in `tests/Feature`.
