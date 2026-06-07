<?php

use Illuminate\Support\Facades\Route;
use Modules\Osr\Http\Controllers\EquipmentInstanceController;
use Modules\Osr\Http\Controllers\StockController;

/*
| OSR / equipment API (PLM-CFG-06, OSR-01, OSR-INSTANCE-01).
| Reads: stock.read; writes: stock.manage (DD_EM-CFG-03).
*/

Route::middleware('auth:sanctum')->group(function () {
    // PLM-CFG-06 SKUs
    Route::get('equipment-skus', [StockController::class, 'skus'])->middleware('permission:stock.read');
    Route::post('equipment-skus', [StockController::class, 'storeSku'])->middleware('permission:stock.manage');

    // OSR-01 stock chain
    Route::get('stock-locations', [StockController::class, 'locations'])->middleware('permission:stock.read');
    Route::post('stock-locations', [StockController::class, 'storeLocation'])->middleware('permission:stock.manage');
    Route::get('stock-balances', [StockController::class, 'balances'])->middleware('permission:stock.read');
    Route::post('stock-movements', [StockController::class, 'move'])->middleware(['permission:stock.manage', 'idempotency']);

    // OSR-INSTANCE-01 serialized instances
    Route::get('equipment-instances', [EquipmentInstanceController::class, 'index'])->middleware('permission:stock.read');
    Route::post('equipment-instances', [EquipmentInstanceController::class, 'store'])->middleware(['permission:stock.manage', 'idempotency']);
    Route::get('equipment-instances/{equipmentInstance}', [EquipmentInstanceController::class, 'show'])->middleware('permission:stock.read');
    Route::post('equipment-instances/{equipmentInstance}/transition', [EquipmentInstanceController::class, 'transition'])->middleware('permission:stock.manage');
});
