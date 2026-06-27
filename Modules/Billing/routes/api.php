<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Dunning\Http\Controllers\DunningController;
use Modules\Billing\Invoicing\Http\Controllers\InvoiceController;
use Modules\Billing\Payments\Http\Controllers\PaymentController;

/*
| Billing API (BIL-02 invoicing, BIL-01-PAY-01 payments, BIL-05 wallet).
| BIL owns money; account_id is the billing partition (DD_EM-CFG-03 perms).
*/

Route::middleware('auth:sanctum')->group(function () {
    // BIL-04 dunning routes now live in the BillingDunning module.

    // BIL-02-TAX-01 tax routes now live in the BillingTax module.


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

    // BIL-02-ADJ-01 adjustments / BIL-01-CN-01 notes / GEN-01 bulk reversal now live in the BillingAdjustments module.

    // BIL-CFG-01 BillableEvent + MED-01 usage routes now live in the BillingMediation module.

});
