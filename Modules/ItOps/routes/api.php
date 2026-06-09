<?php

use Illuminate\Support\Facades\Route;
use Modules\ItOps\Http\Controllers\ItOpsController;
use Modules\ItOps\Http\Controllers\NocController;

/*
| IT-Ops console API (DD_QA / ops). Reads: itops.view; control: itops.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('itops/logs', [ItOpsController::class, 'logs'])->middleware('permission:itops.view');
    Route::get('itops/services', [ItOpsController::class, 'services'])->middleware('permission:itops.view');
    Route::post('itops/services/{service}/restart', [ItOpsController::class, 'restart'])->middleware('permission:itops.manage');

    // NOC console: overview, SLA breaches, end-to-end traces, service start/stop.
    Route::get('noc/overview', [NocController::class, 'overview'])->middleware('permission:itops.view');
    Route::get('noc/sla-overdue', [NocController::class, 'slaOverdue'])->middleware('permission:itops.view');
    Route::get('noc/trace', [NocController::class, 'trace'])->middleware('permission:itops.view');
    Route::post('noc/services/{service}/stop', [NocController::class, 'stop'])->middleware('permission:itops.manage');
    Route::post('noc/services/{service}/start', [NocController::class, 'start'])->middleware('permission:itops.manage');
});
