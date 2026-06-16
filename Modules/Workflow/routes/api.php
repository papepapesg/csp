<?php

use Illuminate\Support\Facades\Route;
use Modules\Workflow\Http\Controllers\WorkflowOpsController;
use Modules\Workflow\Http\Controllers\WorkflowStudioController;

/*
| Workflow engine API (FOUNDATION_CAMUNDA). Studio authoring under workflow.manage;
| IT-Ops monitoring under workflow.view / itops.view.
*/

Route::middleware('auth:sanctum')->group(function () {
    // Studio (React Flow)
    Route::get('workflow/palette', [WorkflowStudioController::class, 'palette'])->middleware('permission:workflow.view');
    Route::get('workflow/definitions', [WorkflowStudioController::class, 'index'])->middleware('permission:workflow.view');
    Route::post('workflow/definitions', [WorkflowStudioController::class, 'store'])->middleware('permission:workflow.manage');
    Route::get('workflow/definitions/{processDefinition}', [WorkflowStudioController::class, 'show'])->middleware('permission:workflow.view');
    Route::put('workflow/definitions/{processDefinition}', [WorkflowStudioController::class, 'update'])->middleware('permission:workflow.manage');
    Route::post('workflow/definitions/{processDefinition}/validate', [WorkflowStudioController::class, 'validate'])->middleware('permission:workflow.view');
    Route::post('workflow/definitions/{processDefinition}/deploy', [WorkflowStudioController::class, 'deploy'])->middleware('permission:workflow.manage');

    // IT-Ops monitoring + control
    Route::get('workflow/instances', [WorkflowOpsController::class, 'instances'])->middleware('permission:workflow.view');
    Route::get('workflow/instances/{instance}', [WorkflowOpsController::class, 'instance'])->middleware('permission:workflow.view');
    Route::get('workflow/tasks', [WorkflowOpsController::class, 'tasks'])->middleware('permission:workflow.view');
    Route::get('workflow/user-tasks', [WorkflowOpsController::class, 'userTasks'])->middleware('permission:workflow.view');
    Route::post('workflow/user-tasks/{userTask}/complete', [WorkflowOpsController::class, 'completeUserTask'])->middleware('permission:workflow.manage');
    Route::post('workflow/incidents/{externalTask}/retry', [WorkflowOpsController::class, 'retryTask'])->middleware('permission:workflow.manage');
    Route::post('workflow/messages/correlate', [WorkflowOpsController::class, 'correlate'])->middleware('permission:workflow.manage');
});
