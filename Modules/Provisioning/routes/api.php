<?php

use Illuminate\Support\Facades\Route;
use Modules\Provisioning\Http\Controllers\ProvisioningController;

/*
| PROV-INT-01 provisioning command + reconciliation API (DD_API-00).
| Reads: provisioning.view; control: provisioning.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('provisioning/commands', [ProvisioningController::class, 'index'])->middleware('permission:provisioning.view');
    Route::get('provisioning/commands/{provisioningCommand}', [ProvisioningController::class, 'show'])->middleware('permission:provisioning.view');
    Route::post('provisioning/commands/{provisioningCommand}/retry', [ProvisioningController::class, 'retry'])->middleware(['permission:provisioning.manage', 'idempotency']);
    Route::post('provisioning/reconcile', [ProvisioningController::class, 'reconcile'])->middleware('permission:provisioning.manage');

    // PROV-INT-01 §7.3 desired-vs-observed reconciliation (NOC review + force-sync).
    Route::get('provisioning/reconciliation/runs', [ProvisioningController::class, 'reconciliationRuns'])->middleware('permission:provisioning.view');
    Route::get('provisioning/reconciliation/items', [ProvisioningController::class, 'reconciliationItems'])->middleware('permission:provisioning.view');
    Route::post('provisioning/reconciliation/run', [ProvisioningController::class, 'reconciliationRun'])->middleware('permission:provisioning.manage');
    Route::post('provisioning/reconciliation/items/{item}/force-sync', [ProvisioningController::class, 'forceSync'])->middleware(['permission:provisioning.manage', 'idempotency']);

    // PROV-INT-01 §11.5 force-sync approval lifecycle (R-PROV-07: approve before execute).
    Route::get('provisioning/force-sync-requests', [ProvisioningController::class, 'forceSyncRequests'])->middleware('permission:provisioning.view');
    Route::post('provisioning/force-sync-requests/{forceSync}/approve', [ProvisioningController::class, 'approveForceSync'])->middleware('permission:provisioning.manage');
    Route::post('provisioning/force-sync-requests/{forceSync}/execute', [ProvisioningController::class, 'executeForceSync'])->middleware(['permission:provisioning.manage', 'idempotency']);
    Route::post('provisioning/force-sync-requests/{forceSync}/cancel', [ProvisioningController::class, 'cancelForceSync'])->middleware('permission:provisioning.manage');
});
