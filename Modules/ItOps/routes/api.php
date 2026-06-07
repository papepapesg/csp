<?php

use Illuminate\Support\Facades\Route;
use Modules\ItOps\Http\Controllers\ItOpsController;

/*
| IT-Ops console API (DD_QA / ops). Reads: itops.view; control: itops.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('itops/logs', [ItOpsController::class, 'logs'])->middleware('permission:itops.view');
    Route::get('itops/services', [ItOpsController::class, 'services'])->middleware('permission:itops.view');
    Route::post('itops/services/{service}/restart', [ItOpsController::class, 'restart'])->middleware('permission:itops.manage');
});
