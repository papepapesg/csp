<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\AdjustmentController;
use Modules\Billing\Http\Controllers\BillableEventController;
use Modules\Billing\Http\Controllers\BulkReversalController;
use Modules\Billing\Http\Controllers\DunningController;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\PaymentController;
use Modules\Billing\Http\Controllers\UsageController;
use Modules\Billing\Http\Controllers\WalletController;

/*
| Billing API (BIL-02 invoicing, BIL-01-PAY-01 payments, BIL-05 wallet).
| BIL owns money; account_id is the billing partition (DD_EM-CFG-03 perms).
*/

Route::middleware('auth:sanctum')->group(function () {
    // BIL-04 dunning
    Route::get('dunning', [DunningController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('dunning/run', [DunningController::class, 'run'])->middleware('permission:invoice.manage');
    Route::post('dunning/{account}/clear', [DunningController::class, 'clear'])->middleware('permission:invoice.manage');
    // BIL-02-GEN-01 — Bulk reversal (rule group R; BILLING_ADMIN proposes, FINANCE_HEAD approves)
    Route::get('billing/bulk-reversals', [BulkReversalController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('billing/bulk-reversals/preview', [BulkReversalController::class, 'preview'])->middleware('permission:invoice.manage');
    Route::post('billing/bulk-reversals', [BulkReversalController::class, 'store'])->middleware('permission:invoice.manage');
    Route::post('billing/bulk-reversals/{batch}/approve', [BulkReversalController::class, 'approve'])->middleware('permission:adjustment.approve');
    Route::post('billing/bulk-reversals/{batch}/reject', [BulkReversalController::class, 'reject'])->middleware('permission:adjustment.approve');

    // BIL-02 — Invoices
    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:invoice.read');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoice.read');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware(['permission:invoice.manage', 'idempotency']);
    Route::post('invoices/{invoice}/tax-invoice', [InvoiceController::class, 'issueTaxInvoice'])->middleware(['permission:invoice.manage', 'idempotency']);

    // BIL-01-PAY-01 — Payments
    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payment.read');
    Route::post('payments', [PaymentController::class, 'store'])->middleware(['permission:payment.apply', 'idempotency']);

    // BIL-05 — Wallet
    Route::get('wallets/{subscriptionId}/balance', [WalletController::class, 'balance'])->middleware('permission:wallet.read');
    Route::post('wallets/{subscriptionId}/topup', [WalletController::class, 'topup'])->middleware(['permission:wallet.manage', 'idempotency']);
    Route::post('wallets/{subscriptionId}/debit', [WalletController::class, 'debit'])->middleware('permission:wallet.manage');

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
