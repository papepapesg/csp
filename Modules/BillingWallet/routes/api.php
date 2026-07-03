<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Wallet\Http\Controllers\WalletTypeController;
use Modules\Billing\Wallet\Http\Controllers\WalletController;

/*
| BIL-05 Wallet API (prepaid balances). Keyed by subscriptionId.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('wallets/{subscriptionId}/balance', [WalletController::class, 'balance'])->middleware('permission:wallet.read');
    Route::post('wallets/{subscriptionId}/topup', [WalletController::class, 'topup'])->middleware(['permission:wallet.manage', 'idempotency']);
    Route::post('wallets/{subscriptionId}/debit', [WalletController::class, 'debit'])->middleware('permission:wallet.manage');

    // PLM-CFG-03 wallet catalog (the rich `wallet` entity: applicability, precedence,
    // lifecycle) — authored here, in the module that enforces it at charge time.
    Route::get('wallet-types', [WalletTypeController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('wallet-types', [WalletTypeController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('wallet-types/{wallet}', [WalletTypeController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('wallet-types/{wallet}', [WalletTypeController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('wallet-types/{wallet}/activate', [WalletTypeController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('wallet-types/{wallet}/retire', [WalletTypeController::class, 'retire'])->middleware('permission:catalog.manage');
});
