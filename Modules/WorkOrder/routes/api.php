<?php

use Illuminate\Support\Facades\Route;
use Modules\WorkOrder\FieldAudit\Http\Controllers\FieldAuditCampaignController;
use Modules\WorkOrder\FieldAudit\Http\Controllers\FieldAuditController;
use Modules\WorkOrder\Http\Controllers\WorkOrderController;

/*
| WO-01 Work Order API (DD_API-00). Dispatch: workorder.assign;
| field execution: workorder.execute; reads: workorder.read.
*/

// POC ONLY — unauthenticated read for the YAS Dispatcher Console prototype (RBAC intentionally
// skipped per POC scope; serves the seeded WO data). Remove before production.
Route::get('poc/work-orders', [WorkOrderController::class, 'pocIndex']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('work-orders', [WorkOrderController::class, 'index'])->middleware('permission:workorder.read');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->middleware(['permission:workorder.assign', 'scope:TECH_REGION,tech_region_id', 'idempotency']);
    Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->middleware('permission:workorder.read');
    Route::post('work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign'])->middleware('permission:workorder.assign');
    Route::post('work-orders/{workOrder}/auto-assign', [WorkOrderController::class, 'autoAssign'])->middleware('permission:workorder.assign');
    Route::post('work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->middleware('permission:workorder.execute');
    Route::post('work-orders/{workOrder}/finalize', [WorkOrderController::class, 'finalize'])->middleware('permission:workorder.execute');
    Route::post('work-orders/{workOrder}/finalize-first-confirm', [WorkOrderController::class, 'finalizeFirstConfirm'])->middleware('permission:workorder.execute');
    Route::post('work-orders/{workOrder}/finalize-second-confirm', [WorkOrderController::class, 'finalizeSecondConfirm'])->middleware('permission:workorder.execute');
    Route::post('work-orders/{workOrder}/reassign', [WorkOrderController::class, 'reassign'])->middleware('permission:workorder.assign');
    Route::post('work-orders/{workOrder}/notes', [WorkOrderController::class, 'addNote'])->middleware('permission:workorder.execute');
    Route::post('work-orders/{workOrder}/attachments', [WorkOrderController::class, 'addAttachment'])->middleware('permission:workorder.execute');
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

    // FA-01/02/03 campaign/task/observation/discrepancy model (the unified field-audit capability)
    $fac = FieldAuditCampaignController::class;
    Route::post('field-audit-campaigns', [$fac, 'createCampaign'])->middleware('permission:workorder.assign');
    Route::get('field-audit-tasks', [$fac, 'tasks'])->middleware('permission:workorder.read');
    Route::post('field-audit-tasks', [$fac, 'createTask'])->middleware(['permission:workorder.assign', 'idempotency']);
    Route::get('field-audit-tasks/{fieldAuditTask}', [$fac, 'showTask'])->middleware('permission:workorder.read');
    Route::post('field-audit-tasks/{fieldAuditTask}/observations', [$fac, 'submitObservation'])->middleware('permission:workorder.execute');
    Route::get('field-audit-discrepancies', [$fac, 'discrepancies'])->middleware('permission:workorder.read');
    Route::post('field-audit-discrepancies/{fieldAuditDiscrepancy}/approval-outcome', [$fac, 'approvalOutcome'])->middleware('permission:workorder.assign');
    Route::post('field-audit-discrepancies/{fieldAuditDiscrepancy}/resolve', [$fac, 'resolveDiscrepancy'])->middleware('permission:workorder.assign');
});
