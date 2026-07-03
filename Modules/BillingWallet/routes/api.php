<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Wallet\Http\Controllers\WalletCatalogController;
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
    Route::get('wallet-catalog', [WalletCatalogController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('wallet-catalog', [WalletCatalogController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('wallet-catalog/{wallet}', [WalletCatalogController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('wallet-catalog/{wallet}', [WalletCatalogController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('wallet-catalog/{wallet}/activate', [WalletCatalogController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('wallet-catalog/{wallet}/retire', [WalletCatalogController::class, 'retire'])->middleware('permission:catalog.manage');
});
