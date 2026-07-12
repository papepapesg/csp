# Billing Wallet

Manages customer monetary and allowance wallets, ledger entries, expiry, top-ups, consumption and multi-wallet allocation.

## Use

Call wallet services/APIs for top-up and consumption; run `php artisan sophix:wallet:expire` for scheduled expiry processing.

## Configure

Wallet types, units, priority, expiry and carry-over rules are catalogs/configuration. Balances are derived from immutable ledger entries and must not be edited directly.

## Extend

Add wallet policies as registered strategies, preserve ledger idempotency, and publish balance-impact events. New units must define deterministic arithmetic and tests.

## Exposed APIs

- `GET wallet-types`
- `GET wallet-types/{wallet}`
- `GET wallets/{subscriptionId}/balance`
- `PATCH wallet-types/{wallet}`
- `POST wallet-types`
- `POST wallet-types/{wallet}/activate`
- `POST wallet-types/{wallet}/retire`
- `POST wallets/{subscriptionId}/debit`
- `POST wallets/{subscriptionId}/topup`

## Data models

- `Wallet`
- `WalletTransaction`
- `WalletType`

## Services

- `WalletService`
- `WalletTypeService`

## Events

- `BillingEvents::TOPIC`
- `BillingEvents::WALLET_CREDITED`
- `BillingEvents::WALLET_DEBITED`
- `BillingEvents::WALLET_TOPPED_UP`
- `CatalogEvents::TOPIC`
- `CatalogEvents::WALLET_ACTIVATED`
- `CatalogEvents::WALLET_CREATED`
- `CatalogEvents::WALLET_RETIRED`
- `CatalogEvents::WALLET_UPDATED`
- `EvictPlmCatalogCache`

## Commands

- `sophix:wallet:expire`

## Test

Wallet lifecycle and multi-wallet scenarios are in `tests/Feature`.
