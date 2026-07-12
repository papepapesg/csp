# ILM

Customer and account master capability: identity, KYC, documents, account status, flags and customer/account history.

## Use

Use the module APIs/services for customer onboarding, account maintenance and KYC decisions. Review KYC and flags through `sophix:ilm:*` operational commands.

## Configure

Customer/account statuses, sub-statuses, flag definitions and KYC approval policy are catalogs/configuration. Status and KYC histories are immutable records.

## Extend

Add country identity fields through optional document/catalog configuration where possible. New flag effects require a registered system behavior and tests across affected capabilities; consumers should react to events rather than update ILM tables.

## Test

Customer, account, KYC and flag scenarios are in `tests/Feature`.
