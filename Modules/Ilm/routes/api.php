<?php

use Illuminate\Support\Facades\Route;
use Modules\Ilm\Http\Controllers\CustomerAccountController;
use Modules\Ilm\Http\Controllers\CustomerController;
use Modules\Ilm\Http\Controllers\CustomerSubResourceController;
use Modules\Ilm\Http\Controllers\CvmController;
use Modules\Ilm\Http\Controllers\KycController;

/*
| ILM-CFG-01 Customer Service API (DD_API-00 conventions).
| URLs are unversioned per DD_API-00 §4 while internal contracts evolve.
| Mounted under the `api` group (correlation + operator middleware applied).
*/

Route::middleware('auth:sanctum')->group(function () {
    // --- Customers ---
    Route::get('customers/search', [CustomerController::class, 'index'])->middleware('permission:customer.read');
    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customer.read');
    Route::post('customers', [CustomerController::class, 'store'])->middleware(['permission:customer.create', 'idempotency']);
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customer.read');
    Route::get('customers/{customer}/overview', [CustomerController::class, 'overview'])->middleware('permission:customer.read');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customer.update');

    // --- Customer contact methods / notes / interactions ---
    Route::get('customers/{customer}/contact-methods', [CustomerSubResourceController::class, 'contactMethods'])->middleware('permission:customer.read');
    Route::post('customers/{customer}/contact-methods', [CustomerSubResourceController::class, 'storeContactMethod'])->middleware('permission:customer.update');
    Route::get('customers/{customer}/notes', [CustomerSubResourceController::class, 'notes'])->middleware('permission:customer.read');
    Route::post('customers/{customer}/notes', [CustomerSubResourceController::class, 'storeNote'])->middleware('permission:customer.update');
    Route::get('customers/{customer}/interactions', [CustomerSubResourceController::class, 'interactions'])->middleware('permission:customer.read');
    Route::post('customers/{customer}/interactions', [CustomerSubResourceController::class, 'storeInteraction'])->middleware('permission:customer.update');

    // --- KYC ---
    Route::post('customers/{customer}/kyc/documents', [KycController::class, 'storeDocument'])->middleware('permission:customer.update');
    Route::post('customers/{customer}/kyc/l1-approve', [KycController::class, 'l1Approve'])->middleware(['permission:customer.update', 'idempotency']);
    Route::post('customers/{customer}/kyc/final-approve', [KycController::class, 'finalApprove'])->middleware(['permission:customer.update', 'idempotency']);
    Route::post('customers/{customer}/kyc/reject', [KycController::class, 'reject'])->middleware('permission:customer.update');

    // --- Customer accounts ---
    Route::get('customer-accounts', [CustomerAccountController::class, 'index'])->middleware('permission:customer.read');
    Route::post('customer-accounts', [CustomerAccountController::class, 'store'])->middleware(['permission:customer.create', 'idempotency']);
    Route::get('customer-accounts/{account}', [CustomerAccountController::class, 'show'])->middleware('permission:customer.read');
    Route::patch('customer-accounts/{account}', [CustomerAccountController::class, 'update'])->middleware('permission:customer.update');
    Route::get('customer-accounts/{account}/flags', [CustomerAccountController::class, 'flags'])->middleware('permission:customer.read');
    Route::put('customer-accounts/{account}/flags/{flagCode}', [CustomerAccountController::class, 'setFlag'])->middleware('permission:customer.update');
    Route::delete('customer-accounts/{account}/flags/{flagCode}', [CustomerAccountController::class, 'clearFlag'])->middleware('permission:customer.update');

    // EM-03 CVM — evaluation, signal profiles, activities, offers
    Route::post('cvm/customers/{customerId}/evaluate', [CvmController::class, 'evaluate'])->middleware('permission:customer.update');
    Route::get('cvm/profiles/{customerId}', [CvmController::class, 'profile'])->middleware('permission:customer.read');
    Route::get('cvm-activities', [CvmController::class, 'activities'])->middleware('permission:customer.read');
    Route::post('cvm-activities', [CvmController::class, 'createActivity'])->middleware(['permission:customer.update', 'idempotency']);
    Route::post('cvm-activities/{cvmActivity}/close', [CvmController::class, 'closeActivity'])->middleware('permission:customer.update');
    Route::post('cvm-offers', [CvmController::class, 'proposeOffer'])->middleware('permission:customer.update');
    Route::post('cvm-offers/{cvmOffer}/accept', [CvmController::class, 'acceptOffer'])->middleware('permission:customer.update');
    Route::post('cvm-offers/{cvmOffer}/reject', [CvmController::class, 'rejectOffer'])->middleware('permission:customer.update');
});
