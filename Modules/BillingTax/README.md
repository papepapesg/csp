# Billing Tax

Creates, signs, retries and cancels legal tax invoices while preserving authority responses and compliance history.

## Use

Billing invokes the tax generator after eligible financial events. Scheduled scanners sign or retry invoices; operations commands review failures and signing state.

## Configure

Enablement, legal numbering and signer implementation references are operator configuration. Tax calculation catalogs belong to `CatalogTax`; country authority connectivity is provided by a deployment adapter.

## Extend

Implement `TaxInvoiceSigner`, register its implementation key, and map the operator configuration to it. Keep dual control in the approval framework and add contract tests using recorded safe fixtures.

## Exposed APIs

- `GET tax-invoices`
- `GET tax-invoices/dashboard`
- `GET tax-invoices/{taxInvoice}`
- `GET tax-invoices/{taxInvoice}/pdf`
- `GET tax-invoices/{taxInvoice}/signing-history`
- `POST tax-invoices/manual`
- `POST tax-invoices/{taxInvoice}/cancel`
- `POST tax-invoices/{taxInvoice}/cancel/approve`
- `POST tax-invoices/{taxInvoice}/resolve-no-action`
- `POST tax-invoices/{taxInvoice}/retry-signing`

## Data models

- `TaxInvoice`
- `TaxInvoiceSigningFailure`
- `TaxOperatorConfig`

## Services

- `TaxInvoiceGenerator`
- `TaxService`
- `TaxSigningService`

## Events

- `BillingEvents::TAX_INVOICE_CANCELLED`
- `BillingEvents::TAX_INVOICE_GAVE_UP`
- `BillingEvents::TAX_INVOICE_ISSUED`
- `BillingEvents::TAX_INVOICE_SIGNED`
- `BillingEvents::TAX_INVOICE_SIGNING_FAILED`
- `BillingEvents::TOPIC`
- `TaxEventBridge`

## Commands

- `sophix:billing:tax-retry-scan`
- `sophix:billing:tax-sign-scan`

## Test

The complete signing and cancellation lifecycle is covered by `tests/Feature/Tax01Test.php`.
