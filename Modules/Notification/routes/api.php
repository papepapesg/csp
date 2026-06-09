<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\NotificationController;
use Modules\Notification\Http\Controllers\TemplateStudioController;

/*
| NOT-01 notification + ICN-01 internal comms API (DD_API-00).
| Reads: notification.read; send: notification.send.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:notification.read');
    Route::post('notifications', [NotificationController::class, 'send'])->middleware(['permission:notification.send', 'idempotency']);
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])->middleware('permission:notification.read');

    Route::get('internal-messages', [NotificationController::class, 'internalIndex'])->middleware('permission:notification.read');
    Route::post('internal-messages', [NotificationController::class, 'postInternal'])->middleware('permission:notification.send');

    // NOT-01 template studio — per-channel notification templates + invoice layouts.
    Route::get('notification-templates', [TemplateStudioController::class, 'index'])->middleware('permission:notification.read');
    Route::post('notification-templates', [TemplateStudioController::class, 'store'])->middleware('permission:catalog.manage');
    Route::patch('notification-templates/{template}', [TemplateStudioController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('notification-templates/{template}/activate', [TemplateStudioController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('notification-templates/preview', [TemplateStudioController::class, 'preview'])->middleware('permission:notification.read');

    Route::get('invoice-templates', [TemplateStudioController::class, 'invoiceIndex'])->middleware('permission:notification.read');
    Route::post('invoice-templates', [TemplateStudioController::class, 'invoiceStore'])->middleware('permission:catalog.manage');
    Route::patch('invoice-templates/{invoiceTemplate}', [TemplateStudioController::class, 'invoiceUpdate'])->middleware('permission:catalog.manage');
});
