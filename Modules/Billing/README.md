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

## Test

Feature tests are in `tests/Feature`. Run `php artisan test --testsuite=Modules` or target this directory with PHPUnit.
