# Billing Wallet

Manages customer monetary and allowance wallets, ledger entries, expiry, top-ups, consumption and multi-wallet allocation.

## Use

Call wallet services/APIs for top-up and consumption; run `php artisan sophix:wallet:expire` for scheduled expiry processing.

## Configure

Wallet types, units, priority, expiry and carry-over rules are catalogs/configuration. Balances are derived from immutable ledger entries and must not be edited directly.

## Extend

Add wallet policies as registered strategies, preserve ledger idempotency, and publish balance-impact events. New units must define deterministic arithmetic and tests.

## Test

Wallet lifecycle and multi-wallet scenarios are in `tests/Feature`.
