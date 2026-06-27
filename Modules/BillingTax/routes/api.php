<?php

use Illuminate\Support\Facades\Route;

/*
| BIL-02-TAX-01 tax invoice & fiscalisation gateway API.
*/
Route::middleware('auth:sanctum')->group(function () {
    $tax = \Modules\Billing\Tax\Http\Controllers\TaxInvoiceController::class;
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
});
