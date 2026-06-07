<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\PaymentController;
use Modules\Billing\Http\Controllers\WalletController;

/*
| Billing API (BIL-02 invoicing, BIL-01-PAY-01 payments, BIL-05 wallet).
| BIL owns money; account_id is the billing partition (DD_EM-CFG-03 perms).
*/

Route::middleware('auth:sanctum')->group(function () {
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
});
