<?php

use Illuminate\Support\Facades\Route;
use Modules\Workforce\Http\Controllers\WorkforceController;

/*
| EM-02 Contractor & Staff Registry API (DD_API-00).
| Reads: workforce.read; writes: workforce.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('contractors', [WorkforceController::class, 'contractors'])->middleware('permission:workforce.read');
    Route::post('contractors', [WorkforceController::class, 'storeContractor'])->middleware('permission:workforce.manage');
    Route::post('contractors/{contractor}/teams', [WorkforceController::class, 'storeTeam'])->middleware('permission:workforce.manage');
    Route::get('staff', [WorkforceController::class, 'staff'])->middleware('permission:workforce.read');
    Route::post('staff', [WorkforceController::class, 'storeStaff'])->middleware('permission:workforce.manage');
});
