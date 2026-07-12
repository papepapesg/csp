# Billing Tax

Creates, signs, retries and cancels legal tax invoices while preserving authority responses and compliance history.

## Use

Billing invokes the tax generator after eligible financial events. Scheduled scanners sign or retry invoices; operations commands review failures and signing state.

## Configure

Enablement, legal numbering and signer implementation references are operator configuration. Tax calculation catalogs belong to `CatalogTax`; country authority connectivity is provided by a deployment adapter.

## Extend

Implement `TaxInvoiceSigner`, register its implementation key, and map the operator configuration to it. Keep dual control in the approval framework and add contract tests using recorded safe fixtures.

## Test

The complete signing and cancellation lifecycle is covered by `tests/Feature/Tax01Test.php`.
