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
    Route::post('provisioning/commands/{provisioningCommand}/retry', [ProvisioningController::class, 'retry'])->middleware('permission:provisioning.manage');
    Route::post('provisioning/reconcile', [ProvisioningController::class, 'reconcile'])->middleware('permission:provisioning.manage');
});
