# ILM

Customer and account master capability: identity, KYC, documents, account status, flags and customer/account history.

## Use

Use the module APIs/services for customer onboarding, account maintenance and KYC decisions. Review KYC and flags through `sophix:ilm:*` operational commands.

## Configure

Customer/account statuses, sub-statuses, flag definitions and KYC approval policy are catalogs/configuration. Status and KYC histories are immutable records.

## Extend

Add country identity fields through optional document/catalog configuration where possible. New flag effects require a registered system behavior and tests across affected capabilities; consumers should react to events rather than update ILM tables.

## Exposed APIs

- `DELETE customer-accounts/{account}/flags/{flagCode}`
- `GET customer-accounts`
- `GET customer-accounts/{account}`
- `GET customer-accounts/{account}/flags`
- `GET customers`
- `GET customers/search`
- `GET customers/{customer}`
- `GET customers/{customer}/contact-methods`
- `GET customers/{customer}/interactions`
- `GET customers/{customer}/notes`
- `GET customers/{customer}/overview`
- `PATCH customer-accounts/{account}`
- `PATCH customers/{customer}`
- `POST customer-accounts`
- `POST customers`
- `POST customers/{customer}/contact-methods`
- `POST customers/{customer}/interactions`
- `POST customers/{customer}/kyc/documents`
- `POST customers/{customer}/kyc/final-approve`
- `POST customers/{customer}/kyc/l1-approve`
- `POST customers/{customer}/kyc/reject`
- `POST customers/{customer}/notes`
- `PUT customer-accounts/{account}/flags/{flagCode}`

## Data models

- `ContactMethod`
- `Customer`
- `CustomerAccount`
- `CustomerAccountFlag`
- `CustomerAccountFlagCatalog`
- `CustomerInteraction`
- `CustomerKycDocument`
- `CustomerNote`
- `CustomerSubStatusCatalog`
- `KycApproval`

## Services

- `AccountService`
- `CustomerOverviewService`
- `CustomerService`

## Events

- `ApplySubStatusOnApproval`
- `IlmEvents`
- `IlmEvents::ACCOUNT_FLAG_CLEARED`
- `IlmEvents::ACCOUNT_FLAG_SET`
- `IlmEvents::CUSTOMER_ACCOUNT_CREATED`
- `IlmEvents::CUSTOMER_ACCOUNT_STATUS_CHANGED`
- `IlmEvents::CUSTOMER_CREATED`
- `IlmEvents::CUSTOMER_KYC_APPROVED`
- `IlmEvents::CUSTOMER_KYC_REJECTED`
- `IlmEvents::CUSTOMER_UPDATED`
- `IlmEvents::TOPIC`

## Commands

- `sophix:ilm:customer-show`
- `sophix:ilm:flag-clear`
- `sophix:ilm:kyc-queue`

## Test

Customer, account, KYC and flag scenarios are in `tests/Feature`.
