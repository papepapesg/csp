<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Adjustments\Http\Controllers\AdjustmentController;
use Modules\Billing\Adjustments\Http\Controllers\BulkReversalController;

/*
| BIL-02-ADJ-01 invoice adjustments (credit/debit notes), BIL-01-CN-01 note ledger,
| and BIL-02-GEN-01 rule group R bulk reversal.
*/
Route::middleware('auth:sanctum')->group(function () {
    // BIL-02-GEN-01 — Bulk reversal (BILLING_ADMIN proposes, FINANCE_HEAD approves)
    Route::get('billing/bulk-reversals', [BulkReversalController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('billing/bulk-reversals/preview', [BulkReversalController::class, 'preview'])->middleware('permission:invoice.manage');
    Route::post('billing/bulk-reversals', [BulkReversalController::class, 'store'])->middleware('permission:invoice.manage');
    Route::post('billing/bulk-reversals/{batch}/approve', [BulkReversalController::class, 'approve'])->middleware('permission:adjustment.approve');
    Route::post('billing/bulk-reversals/{batch}/reject', [BulkReversalController::class, 'reject'])->middleware('permission:adjustment.approve');

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
});
