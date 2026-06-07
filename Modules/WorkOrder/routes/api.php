<?php

use Illuminate\Support\Facades\Route;
use Modules\WorkOrder\Http\Controllers\FieldAuditController;
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

    // WO-01-FLOW-SUPPORT orchestration
    Route::post('work-orders/{workOrder}/support-flow', [WorkOrderController::class, 'startSupportFlow'])->middleware('permission:workorder.assign');
    Route::post('work-orders/{workOrder}/resolve', [WorkOrderController::class, 'resolve'])->middleware('permission:workorder.execute');

    // WO-01-FLOW-SHIFTING orchestration
    Route::post('work-orders/{workOrder}/shifting-flow', [WorkOrderController::class, 'startShiftingFlow'])->middleware('permission:workorder.assign');
    Route::post('work-orders/{workOrder}/advance-phase', [WorkOrderController::class, 'advancePhase'])->middleware('permission:workorder.execute');

    // FA-01/02/03 field audits
    Route::get('field-audits', [FieldAuditController::class, 'index'])->middleware('permission:workorder.read');
    Route::post('field-audits', [FieldAuditController::class, 'store'])->middleware('permission:workorder.assign');
    Route::get('field-audits/{fieldAudit}', [FieldAuditController::class, 'show'])->middleware('permission:workorder.read');
    Route::post('field-audits/{fieldAudit}/findings', [FieldAuditController::class, 'submitFindings'])->middleware('permission:workorder.execute');
});
