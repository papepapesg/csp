<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\AdminNotificationController;
use Modules\Notification\Http\Controllers\AdminTemplateController;
use Modules\Notification\Http\Controllers\CustomerPreferenceController;
use Modules\Notification\Http\Controllers\NotificationController;
use Modules\Notification\Http\Controllers\TemplateStudioController;

/*
| NOT-01 notification + ICN-01 internal comms API (DD_API-00).
| Reads: notification.read; send: notification.send; admin ops: notification.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    // NOT-01 full model — customer self-service preferences (P-1/P-2).
    Route::get('notifications/preferences', [CustomerPreferenceController::class, 'show'])->middleware('permission:selfcare.access');
    Route::put('notifications/preferences', [CustomerPreferenceController::class, 'update'])->middleware('permission:selfcare.access');

    // NOT-01 admin operations (rule group O) + bounce intake (F-5).
    Route::post('admin/notifications/send', [AdminNotificationController::class, 'send'])->middleware(['permission:notification.manage', 'idempotency']);
    Route::post('admin/notifications/{notificationLog}/resend', [AdminNotificationController::class, 'resend'])->middleware(['permission:notification.manage', 'idempotency']);
    Route::get('admin/notifications/dashboard', [AdminNotificationController::class, 'dashboard'])->middleware('permission:notification.manage');
    Route::get('admin/notifications/failure-queue', [AdminNotificationController::class, 'failureQueue'])->middleware('permission:notification.manage');
    Route::post('admin/notifications/render-failures/{renderFailureQueue}/retry', [AdminNotificationController::class, 'retryRender'])->middleware('permission:notification.manage');
    Route::post('admin/notifications/routing/pause', [AdminNotificationController::class, 'pauseRouting'])->middleware('permission:notification.manage');
    Route::post('admin/notifications/bounces', [AdminNotificationController::class, 'processBounce'])->middleware('permission:notification.manage');

    // NOT-01 admin template management (O-3) — the format-decomposed, versioned `template`.
    Route::get('admin/templates', [AdminTemplateController::class, 'index'])->middleware('permission:notification.template.manage');
    Route::post('admin/templates', [AdminTemplateController::class, 'store'])->middleware('permission:notification.template.manage');
    Route::post('admin/templates/{template}/preview', [AdminTemplateController::class, 'preview'])->middleware('permission:notification.template.manage');
    Route::post('admin/templates/{template}/activate', [AdminTemplateController::class, 'activate'])->middleware('permission:notification.template.manage');
    Route::post('admin/templates/{template}/deactivate', [AdminTemplateController::class, 'deactivate'])->middleware('permission:notification.template.manage');

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

    // ICN-01 staff internal communications.
    $icn = \Modules\Notification\Http\Controllers\Icn\StaffNotificationController::class;
    $icnCat = \Modules\Notification\Http\Controllers\Icn\StaffCatalogController::class;

    Route::post('staff-notifications', [$icn, 'dispatch'])->middleware('permission:staff_notification.dispatch');
    Route::get('staff-notifications/inbox', [$icn, 'inbox']);                 // any authenticated staff user (own inbox)
    Route::get('staff-notifications/stats', [$icn, 'stats'])->middleware('permission:staff_notification.manage');
    Route::get('staff-notifications', [$icn, 'index'])->middleware('permission:staff_notification.manage');
    Route::get('staff-notifications/{staffNotification}', [$icn, 'show'])->middleware('permission:staff_notification.manage');
    Route::post('staff-notifications/{staffNotification}/ack', [$icn, 'ack']); // staff user acks their own

    // ICN-01 catalog management
    Route::get('staff-notification-templates', [$icnCat, 'templates'])->middleware('permission:staff_notification.manage');
    Route::post('staff-notification-templates', [$icnCat, 'upsertTemplate'])->middleware('permission:staff_notification.manage');
    Route::put('staff-notification-templates/{operator}/{code}/{channel}/disable', [$icnCat, 'disableTemplate'])->middleware('permission:staff_notification.manage');
    Route::get('staff-notification-channel-config', [$icnCat, 'channelConfig'])->middleware('permission:staff_notification.manage');
    Route::put('staff-notification-channel-config/{operator}', [$icnCat, 'putChannelConfig'])->middleware('permission:staff_notification.manage');
    Route::get('staff-notification-adapter-bindings', [$icnCat, 'bindings'])->middleware('permission:staff_notification.manage');
    Route::put('staff-notification-adapter-bindings/{operator}/{channel}', [$icnCat, 'putBinding'])->middleware('permission:staff_notification.manage');
    Route::get('icn/adapter-registry', [$icnCat, 'adapterRegistry'])->middleware('permission:staff_notification.manage');
    Route::get('staff-notification-user-pref/{userId}', [$icnCat, 'userPref']);
    Route::put('staff-notification-user-pref/{userId}', [$icnCat, 'putUserPref']);
    Route::get('staff-notification-user-channel-identity/{userId}', [$icnCat, 'userIdentities']);
    Route::put('staff-notification-user-channel-identity/{userId}/{channel}', [$icnCat, 'putUserIdentity']);
    Route::delete('staff-notification-user-channel-identity/{userId}/{channel}', [$icnCat, 'deleteUserIdentity'])->middleware('permission:staff_notification.manage');
    Route::get('staff-groups/{group}/members', [$icnCat, 'groupMembers'])->middleware('permission:staff_notification.manage');
});
