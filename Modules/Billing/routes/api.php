<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Adjustments\Http\Controllers\AdjustmentController;
use Modules\Billing\Mediation\Http\Controllers\BillableEventController;
use Modules\Billing\Adjustments\Http\Controllers\BulkReversalController;
use Modules\Billing\Dunning\Http\Controllers\DunningController;
use Modules\Billing\Invoicing\Http\Controllers\InvoiceController;
use Modules\Billing\Payments\Http\Controllers\PaymentController;
use Modules\Billing\Mediation\Http\Controllers\UsageController;

/*
| Billing API (BIL-02 invoicing, BIL-01-PAY-01 payments, BIL-05 wallet).
| BIL owns money; account_id is the billing partition (DD_EM-CFG-03 perms).
*/

Route::middleware('auth:sanctum')->group(function () {
    // BIL-04 dunning routes now live in the BillingDunning module.

    // BIL-02-TAX-01 tax routes now live in the BillingTax module.

    // BIL-02-GEN-01 — Bulk reversal (rule group R; BILLING_ADMIN proposes, FINANCE_HEAD approves)
    Route::get('billing/bulk-reversals', [BulkReversalController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('billing/bulk-reversals/preview', [BulkReversalController::class, 'preview'])->middleware('permission:invoice.manage');
    Route::post('billing/bulk-reversals', [BulkReversalController::class, 'store'])->middleware('permission:invoice.manage');
    Route::post('billing/bulk-reversals/{batch}/approve', [BulkReversalController::class, 'approve'])->middleware('permission:adjustment.approve');
    Route::post('billing/bulk-reversals/{batch}/reject', [BulkReversalController::class, 'reject'])->middleware('permission:adjustment.approve');

    // BIL-02 — Invoices
    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:invoice.read');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoice.read');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware('permission:invoice.read');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware(['permission:invoice.manage', 'idempotency']);
    Route::post('invoices/{invoice}/tax-invoice', [InvoiceController::class, 'issueTaxInvoice'])->middleware(['permission:invoice.manage', 'idempotency']);

    // BIL-01-PAY-01 — Payments
    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payment.read');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payment.read');
    Route::post('payments', [PaymentController::class, 'store'])->middleware(['permission:payment.apply', 'idempotency']);
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('permission:payment.reverse');
    Route::post('payments/{payment}/allocate-surplus', [PaymentController::class, 'allocateSurplus'])->middleware('permission:payment.apply');

    // BIL-CYCLE-01 — cycle-close run monitor (read-only)
    Route::get('cycle-close-runs', [\Modules\Billing\Invoicing\Http\Controllers\CycleCloseController::class, 'index'])->middleware('permission:invoice.read');

    // BIL-05 — Wallet routes now live in the BillingWallet module.

    // BIL-02-ADJ-01 — Invoice adjustments (proposal → approval → note application)
    Route::get('adjustments', [AdjustmentController::class, 'index'])->middleware('permission:invoice.read');
    Route::get('adjustment-reason-codes', [AdjustmentController::class, 'reasonCodes'])->middleware('permission:invoice.read');
    Route::get('adjustments/{adjustment}', [AdjustmentController::class, 'show'])->middleware('permission:invoice.read');
    Route::post('adjustments', [AdjustmentController::class, 'store'])->middleware(['permission:adjustment.create', 'idempotency']);
    Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->middleware('permission:adjustment.approve');
    Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->middleware('permission:adjustment.approve');
    Route::post('adjustments/{adjustment}/request-revision', [AdjustmentController::class, 'requestRevision'])->middleware('permission:adjustment.approve');
    Route::post('adjustments/{adjustment}/cancel', [AdjustmentController::class, 'cancel'])->middleware('permission:adjustment.create');
    Route::post('adjustments/{adjustment}/override-limit', [AdjustmentController::class, 'overrideLimit'])->middleware('permission:adjustment.approve');
    Route::post('adjustments/{adjustment}/retry-application', [AdjustmentController::class, 'retryApplication'])->middleware('permission:adjustment.approve');
    // BIL-01-CN-01 — note document + application ledger
    Route::get('credit-notes/{note}', [AdjustmentController::class, 'showNote'])->middleware('permission:invoice.read');

    // BIL-CFG-01 — BillableEvent catalog (admin)
    Route::get('billing/billable-events', [BillableEventController::class, 'index'])->middleware('permission:catalog.read');
    Route::get('billing/billable-event-categories', [BillableEventController::class, 'categories'])->middleware('permission:catalog.read');
    Route::get('billing/billable-events/{billableEvent}', [BillableEventController::class, 'show'])->middleware('permission:catalog.read');
    Route::post('billing/billable-events', [BillableEventController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::patch('billing/billable-events/{billableEvent}', [BillableEventController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('billing/billable-events/{billableEvent}/activate', [BillableEventController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('billing/billable-events/{billableEvent}/retire', [BillableEventController::class, 'retire'])->middleware('permission:catalog.manage');

    // MED-01 mediation + RAT-01 rating
    Route::get('usage', [UsageController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('usage', [UsageController::class, 'ingest'])->middleware(['permission:invoice.manage', 'idempotency']);
    Route::post('usage/rate-run', [UsageController::class, 'rateRun'])->middleware('permission:invoice.manage');
    Route::get('rated-events', [UsageController::class, 'ratedEvents'])->middleware('permission:invoice.read');
});
