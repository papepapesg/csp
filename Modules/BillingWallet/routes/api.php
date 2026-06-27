<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Wallet\Http\Controllers\WalletController;

/*
| BIL-05 Wallet API (prepaid balances). Keyed by subscriptionId.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('wallets/{subscriptionId}/balance', [WalletController::class, 'balance'])->middleware('permission:wallet.read');
    Route::post('wallets/{subscriptionId}/topup', [WalletController::class, 'topup'])->middleware(['permission:wallet.manage', 'idempotency']);
    Route::post('wallets/{subscriptionId}/debit', [WalletController::class, 'debit'])->middleware('permission:wallet.manage');
});
