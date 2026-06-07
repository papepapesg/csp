<?php

use Illuminate\Support\Facades\Route;
use Modules\WorkOrder\Http\Controllers\WorkOrderController;

/*
| WO-01 Work Order API (DD_API-00). Dispatch: workorder.assign;
| field execution: workorder.execute; reads: workorder.read.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('work-orders', [WorkOrderController::class, 'index'])->middleware('permission:workorder.read');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->middleware(['permission:workorder.assign', 'idempotency']);
    Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->middleware('permission:workorder.read');
    Route::post('work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign'])->middleware('permission:workorder.assign');
    Route::post('work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->middleware('permission:workorder.execute');
    Route::post('work-orders/{workOrder}/finalize', [WorkOrderController::class, 'finalize'])->middleware('permission:workorder.execute');
    Route::post('work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])->middleware('permission:workorder.assign');
});
