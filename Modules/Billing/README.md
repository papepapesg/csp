# Billing

Core revenue-accounting capability for invoices, payments, cycle close, pro-forma documents and financial adjustments shared by the billing satellites.

## Use

- Use the authenticated endpoints in `routes/api.php` for invoice and payment operations.
- Run recurring jobs through the module commands registered by `BillingServiceProvider`.
- Inspect operational queues with `php artisan sophix:billing:ops-status --operator=<code>`.

## Configure

Operator billing mode, cycles, invoice sequencing, payment allocation and generation policies belong in module configuration/catalog tables. Monetary history remains append-only; corrections use notes, reversals or adjustments.

## Extend

Add a service or handler for new executable behavior, expose it through a thin controller or workflow task, publish an outbox event, and add a migration plus tests. Country tax behavior belongs in `BillingTax`; wallet, dunning, intent, mediation and adjustments belong in their satellite modules.

## Exposed APIs

- `GET cycle-close-runs`
- `GET invoices`
- `GET invoices/{invoice}`
- `GET invoices/{invoice}/pdf`
- `GET payments`
- `GET payments/{payment}`
- `POST invoices`
- `POST invoices/{invoice}/tax-invoice`
- `POST payments`
- `POST payments/{payment}/allocate-surplus`
- `POST payments/{payment}/reverse`

## Data models

- `AccountCreditBalance`
- `Invoice`
- `InvoiceLine`
- `NoteApplication`
- `PaymentAllocation`
- `PaymentLedger`

## Services

- `Charge`
- `ChargeComputeService`
- `CustomerSnapshotService`
- `CycleBillingService`
- `CycleCloseService`
- `GenerationFailureService`
- `InvoiceService`
- `NoteApplicationService`
- `PaymentService`
- `ProFormaService`

## Events

- `ApplyCreditBalanceOnInvoice`
- `BillingEvents`
- `BillingEvents::CREDIT_BALANCE_ADJUSTED`
- `BillingEvents::CREDIT_NOTE_APPLIED`
- `BillingEvents::CREDIT_NOTE_ISSUED`
- `BillingEvents::CYCLE_ACTIVATED`
- `BillingEvents::CYCLE_BILLED`
- `BillingEvents::CYCLE_CLOSED`
- `BillingEvents::CYCLE_PAYMENT_MISSED`
- `BillingEvents::DEBIT_NOTE_APPLICATION_FAILED`
- `BillingEvents::DEBIT_NOTE_APPLIED`
- `BillingEvents::DEBIT_NOTE_ISSUED`
- `BillingEvents::INVOICE_GENERATED`
- `BillingEvents::INVOICE_PAID`
- `BillingEvents::NOTE_APPLICATION_FAILED`
- `BillingEvents::OVERPAYMENT_PENDING_REVIEW`
- `BillingEvents::PAYMENT_APPLIED`
- `BillingEvents::PAYMENT_RECEIVED`
- `BillingEvents::PAYMENT_RECEIVED_UNALLOCATED`
- `BillingEvents::PAYMENT_REVERSED`
- `BillingEvents::TOPIC`
- `RetryFrozenCycleOnTopup`

## Commands

- `sophix:billing:cycle-close`
- `sophix:billing:generation-retry`
- `sophix:billing:ops-status`
- `sophix:billing:pro-forma`
- `sophix:billing:run-cycle`

## Test

Feature tests are in `tests/Feature`. Run `php artisan test --testsuite=Modules` or target this directory with PHPUnit.
