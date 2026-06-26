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
    Route::get('dunning/pending-termination-review', [DunningController::class, 'pendingTerminationReview'])->middleware('permission:invoice.read');
    Route::post('dunning/run', [DunningController::class, 'run'])->middleware('permission:invoice.manage');
    Route::post('dunning/refresh-debt', [DunningController::class, 'refreshDebt'])->middleware('permission:invoice.manage');
    Route::get('dunning/{account}', [DunningController::class, 'show'])->middleware('permission:invoice.read');
    Route::get('dunning/{account}/history', [DunningController::class, 'history'])->middleware('permission:invoice.read');
    Route::post('dunning/{account}/clear', [DunningController::class, 'clear'])->middleware('permission:invoice.manage');
    Route::post('dunning/{account}/admin-clear', [DunningController::class, 'adminClear'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/clear-without-payment', [DunningController::class, 'clearWithoutPayment'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/advance', [DunningController::class, 'advance'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/hold', [DunningController::class, 'hold'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/confirm-termination', [DunningController::class, 'confirmTermination'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/force-terminate', [DunningController::class, 'forceTerminate'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/extend-review', [DunningController::class, 'extendReview'])->middleware('permission:dunning.admin');

    // BIL-02-TAX-01 tax invoice & gateway
    $tax = \Modules\Billing\Http\Controllers\TaxInvoiceController::class;
    Route::get('tax-invoices/dashboard', [$tax, 'dashboard'])->middleware('permission:invoice.read');
    Route::get('tax-invoices', [$tax, 'index'])->middleware('permission:invoice.read');
    Route::post('tax-invoices/manual', [$tax, 'manual'])->middleware(['permission:invoice.manage', 'idempotency']);
    Route::get('tax-invoices/{taxInvoice}', [$tax, 'show'])->middleware('permission:invoice.read');
    Route::get('tax-invoices/{taxInvoice}/pdf', [$tax, 'pdf'])->middleware('permission:invoice.read');
    Route::get('tax-invoices/{taxInvoice}/signing-history', [$tax, 'signingHistory'])->middleware('permission:invoice.read');
    Route::post('tax-invoices/{taxInvoice}/retry-signing', [$tax, 'retrySigning'])->middleware('permission:invoice.manage');
    Route::post('tax-invoices/{taxInvoice}/resolve-no-action', [$tax, 'resolveNoAction'])->middleware('permission:invoice.manage');
    Route::post('tax-invoices/{taxInvoice}/cancel', [$tax, 'cancel'])->middleware('permission:invoice.manage');
    Route::post('tax-invoices/{taxInvoice}/cancel/approve', [$tax, 'approveCancel'])->middleware('permission:tax.compliance');

    // BIL-04 dunning program catalog (versioned policy)
    Route::get('dunning-programs', [\Modules\Billing\Http\Controllers\DunningProgramController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('dunning-programs', [\Modules\Billing\Http\Controllers\DunningProgramController::class, 'store'])->middleware('permission:dunning.admin');
    Route::get('dunning-programs/{code}', [\Modules\Billing\Http\Controllers\DunningProgramController::class, 'show'])->middleware('permission:invoice.read');
    Route::post('dunning-programs/{code}/new-version', [\Modules\Billing\Http\Controllers\DunningProgramController::class, 'newVersion'])->middleware('permission:dunning.admin');
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
    Route::get('cycle-close-runs', [\Modules\Billing\Http\Controllers\CycleCloseController::class, 'index'])->middleware('permission:invoice.read');

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
