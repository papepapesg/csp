<?php

use Illuminate\Support\Facades\Route;
use Modules\Osr\Http\Controllers\EquipmentInstanceController;
use Modules\Osr\Procurement\Http\Controllers\ProcurementController;
use Modules\Osr\Http\Controllers\StockController;
use Modules\Osr\Swap\Http\Controllers\SwapRequestController;

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
    Route::post('stock-movements/approvals/{approvalRequest}/decide', [StockController::class, 'decideMovement'])->middleware('permission:stock.manage');
    Route::get('stock-availability', [StockController::class, 'availability'])->middleware('permission:stock.read');
    Route::post('stock-reservations', [StockController::class, 'reserve'])->middleware('permission:stock.manage');
    Route::post('stock-reservations/{woId}/consume', [StockController::class, 'consumeReservation'])->middleware('permission:stock.manage');
    Route::post('stock-reservations/{woId}/release', [StockController::class, 'releaseReservation'])->middleware('permission:stock.manage');

    // OSR-INSTANCE-01 serialized instances
    Route::get('equipment-instances', [EquipmentInstanceController::class, 'index'])->middleware('permission:stock.read');
    Route::post('equipment-instances', [EquipmentInstanceController::class, 'store'])->middleware(['permission:stock.manage', 'idempotency']);
    Route::get('equipment-instances/{equipmentInstance}', [EquipmentInstanceController::class, 'show'])->middleware('permission:stock.read');
    Route::post('equipment-instances/{equipmentInstance}/transition', [EquipmentInstanceController::class, 'transition'])->middleware('permission:stock.manage');

    // OSR-RMA-01 equipment swap & RMA
    Route::get('swap-requests', [SwapRequestController::class, 'index'])->middleware('permission:stock.read');
    Route::get('swap-requests/{swapRequest}', [SwapRequestController::class, 'show'])->middleware('permission:stock.read');
    Route::post('swap-requests/{swapRequest}/field-visit', [SwapRequestController::class, 'fieldVisit'])->middleware(['permission:stock.manage', 'idempotency']);
    Route::post('swap-requests/{kind}', [SwapRequestController::class, 'store'])->middleware(['permission:stock.manage', 'idempotency']);

    // OSR-02 procurement
    Route::get('purchase-orders', [ProcurementController::class, 'index'])->middleware('permission:stock.read');
    Route::post('purchase-orders', [ProcurementController::class, 'store'])->middleware(['permission:stock.manage', 'idempotency']);
    Route::post('purchase-orders/{purchaseOrder}/approve', [ProcurementController::class, 'approve'])->middleware('permission:stock.manage');
    Route::post('purchase-orders/approvals/{approvalRequest}/decide', [ProcurementController::class, 'decide'])->middleware('permission:stock.manage');
    Route::post('purchase-orders/{purchaseOrder}/receive', [ProcurementController::class, 'receive'])->middleware(['permission:stock.manage', 'idempotency']);
    // OSR-05 inventory audit
    Route::post('stock-counts', [ProcurementController::class, 'openCount'])->middleware('permission:stock.manage');
    Route::post('stock-counts/{stockCountSession}/count', [ProcurementController::class, 'count'])->middleware('permission:stock.manage');
    Route::post('stock-counts/{stockCountSession}/reconcile', [ProcurementController::class, 'reconcile'])->middleware('permission:stock.manage');
});
