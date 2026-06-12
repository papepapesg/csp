<?php

use Illuminate\Support\Facades\Route;
use Modules\Workforce\Http\Controllers\ContractorAvailabilityController;
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

    // EM-02 §5.1/5.2 capacity hot path (the WO module's assign-contractor consumer).
    Route::post('contractor-availability', [ContractorAvailabilityController::class, 'availability'])->middleware('permission:workforce.read');
    Route::post('contractor-slot-commitments', [ContractorAvailabilityController::class, 'commit'])->middleware('permission:workforce.manage');
    Route::post('contractor-slot-commitments/{commitment}/consume', [ContractorAvailabilityController::class, 'consume'])->middleware('permission:workforce.manage');
    Route::delete('contractor-slot-commitments/{commitment}', [ContractorAvailabilityController::class, 'release'])->middleware('permission:workforce.manage');
});
