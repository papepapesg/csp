<?php

use Illuminate\Support\Facades\Route;
use Modules\Rules\Http\Controllers\DecisionTableController;

/*
| FOUNDATION_DROOLS decision-table API (DD_API-00). Reads: rules.view;
| authoring + evaluate: rules.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('rules/decision-tables', [DecisionTableController::class, 'index'])->middleware('permission:rules.view');
    Route::post('rules/decision-tables', [DecisionTableController::class, 'store'])->middleware(['permission:rules.manage', 'idempotency']);
    Route::get('rules/decision-tables/{decisionTable}', [DecisionTableController::class, 'show'])->middleware('permission:rules.view');
    Route::put('rules/decision-tables/{decisionTable}', [DecisionTableController::class, 'update'])->middleware('permission:rules.manage');
    Route::post('rules/{ruleSet}/evaluate', [DecisionTableController::class, 'evaluate'])->middleware('permission:rules.manage');
});
