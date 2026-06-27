<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Dunning\Http\Controllers\DunningController;
use Modules\Billing\Dunning\Http\Controllers\DunningProgramController;

/*
| BIL-04 dunning (collections) + versioned dunning-program catalog API.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('dunning', [DunningController::class, 'index'])->middleware('permission:invoice.read');
    Route::get('dunning/pending-termination-review', [DunningController::class, 'pendingTerminationReview'])->middleware('permission:invoice.read');
    Route::post('dunning/run', [DunningController::class, 'run'])->middleware('permission:invoice.manage');
    Route::post('dunning/refresh-debt', [DunningController::class, 'refreshDebt'])->middleware('permission:invoice.manage');
    Route::get('dunning/{account}', [DunningController::class, 'show'])->middleware('permission:invoice.read');
    Route::get('dunning/{account}/history', [DunningController::class, 'history'])->middleware('permission:invoice.read');
    Route::post('dunning/{account}/clear', [DunningController::class, 'clear'])->middleware('permission:invoice.manage');
    Route::post('dunning/{account}/admin-clear', [DunningController::class, 'adminClear'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/clear-without-payment', [DunningController::class, 'clearWithoutPayment'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/advance', [DunningController::class, 'advance'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/hold', [DunningController::class, 'hold'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/confirm-termination', [DunningController::class, 'confirmTermination'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/force-terminate', [DunningController::class, 'forceTerminate'])->middleware('permission:dunning.admin');
    Route::post('dunning/{account}/extend-review', [DunningController::class, 'extendReview'])->middleware('permission:dunning.admin');

    // BIL-04 dunning program catalog (versioned policy)
    Route::get('dunning-programs', [DunningProgramController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('dunning-programs', [DunningProgramController::class, 'store'])->middleware('permission:dunning.admin');
    Route::get('dunning-programs/{code}', [DunningProgramController::class, 'show'])->middleware('permission:invoice.read');
    Route::post('dunning-programs/{code}/new-version', [DunningProgramController::class, 'newVersion'])->middleware('permission:dunning.admin');
});
