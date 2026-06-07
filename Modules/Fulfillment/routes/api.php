<?php

use Illuminate\Support\Facades\Route;
use Modules\Fulfillment\Http\Controllers\FulfillmentOrderController;

/*
| FUL-02 Order Capture + FUL-03 activation API (DD_API-00).
| Reads: fulfillment.read; writes/orchestration: fulfillment.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('fulfillment-orders', [FulfillmentOrderController::class, 'index'])->middleware('permission:fulfillment.read');
    Route::post('fulfillment-orders', [FulfillmentOrderController::class, 'store'])->middleware(['permission:fulfillment.manage', 'idempotency']);
    Route::get('fulfillment-orders/{fulfillmentOrder}', [FulfillmentOrderController::class, 'show'])->middleware('permission:fulfillment.read');
    Route::post('fulfillment-orders/{fulfillmentOrder}/complete', [FulfillmentOrderController::class, 'complete'])->middleware(['permission:fulfillment.manage', 'idempotency']);
    Route::post('fulfillment-orders/{fulfillmentOrder}/cancel', [FulfillmentOrderController::class, 'cancel'])->middleware('permission:fulfillment.manage');
});
