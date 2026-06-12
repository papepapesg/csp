<?php

namespace Modules\Notification\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\NotificationDeliveryAttempt;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Models\NotificationRoutingRule;
use Modules\Notification\Models\RenderFailureQueue;
use Modules\Notification\Services\BounceService;
use Modules\Notification\Services\NotificationOrchestrator;
use Modules\Notification\Services\RenderRetryService;

/**
 * NOT-01 admin operations (rule group O) + the bounce intake (F-5). Manual send/resend,
 * the delivery dashboard, the failure-recovery queue, and emergency routing pause/resume.
 */
class AdminNotificationController extends ApiController
{
    public function __construct(
        private readonly NotificationOrchestrator $orchestrator,
        private readonly RenderRetryService $renderRetry,
        private readonly BounceService $bounces,
    ) {}

    /** POST /api/admin/notifications/send — ad-hoc manual send (O-2, needs NOTIFICATION_SENDER). */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:64'],
            'customer_id' => ['nullable', 'string'],
            'channels' => ['nullable', 'array'],
            'contacts' => ['required', 'array'],
            'payload' => ['nullable', 'array'],
            'source_entity_id' => ['nullable', 'string'],
        ]);
        $log = $this->orchestrator->ingest($data['event_type'], Context::operatorCode(), $data['payload'] ?? [], [
            'customerId' => $data['customer_id'] ?? null,
            'contacts' => $data['contacts'],
            'channels' => $data['channels'] ?? null,
            'sourceEntityId' => $data['source_entity_id'] ?? null,
            'manualResendBy' => $request->user()?->uid,
        ]);

        return $log ? ApiResponse::created($log) : ApiResponse::item(['final_status' => 'NOT_ROUTED']);
    }

    /** POST /api/admin/notifications/{notificationLog}/resend — re-run for the original event (O-1). */
    public function resend(Request $request, NotificationLog $notificationLog): JsonResponse
    {
        $data = $request->validate([
            'channels' => ['nullable', 'array'],
            'contacts' => ['required', 'array'],
            'payload' => ['nullable', 'array'],
            'reason' => ['nullable', 'string'],
        ]);
        $log = $this->orchestrator->ingest($notificationLog->event_type, $notificationLog->operator_code, $data['payload'] ?? [], [
            'customerId' => $notificationLog->customer_id,
            'sourceEntityId' => $notificationLog->source_entity_id,
            'contacts' => $data['contacts'],
            'channels' => $data['channels'] ?? null,
            'manualResendBy' => $request->user()?->uid,
            'originalNotificationId' => $notificationLog->id,
        ]);

        return $log ? ApiResponse::created($log) : ApiResponse::item(['final_status' => 'NOT_ROUTED']);
    }

    /** GET /api/admin/notifications/dashboard — delivery metrics (O-4). */
    public function dashboard(Request $request): JsonResponse
    {
        $operator = $request->query('operatorCode', Context::operatorCode());
        $since = now()->subDays((int) $request->query('days', 7));

        $byStatus = NotificationDeliveryAttempt::query()
            ->where('operator_code', $operator)->where('attempted_at', '>=', $since)
            ->selectRaw('channel, status, count(*) as c')->groupBy('channel', 'status')->get();

        $byFinal = NotificationLog::query()
            ->where('operator_code', $operator)->where('dispatched_at', '>=', $since)
            ->selectRaw('final_status, count(*) as c')->groupBy('final_status')->get()
            ->pluck('c', 'final_status');

        return ApiResponse::item([
            'window_days' => (int) $request->query('days', 7),
            'attempts_by_channel_status' => $byStatus,
            'notifications_by_final_status' => $byFinal,
            'render_queue_depth' => RenderFailureQueue::query()->where('operator_code', $operator)->whereIn('status', [RenderFailureQueue::PENDING_RETRY, RenderFailureQueue::GAVE_UP_AUTO])->count(),
            'escalated_attempts' => NotificationDeliveryAttempt::query()->where('operator_code', $operator)->where('status', NotificationDeliveryAttempt::ESCALATED)->count(),
        ]);
    }

    /** GET /api/admin/notifications/failure-queue — escalated dispatches + stuck renders (O-5). */
    public function failureQueue(Request $request): JsonResponse
    {
        $operator = $request->query('operatorCode', Context::operatorCode());

        return ApiResponse::item([
            'escalated' => NotificationDeliveryAttempt::query()->where('operator_code', $operator)
                ->where('status', NotificationDeliveryAttempt::ESCALATED)->orderByDesc('attempted_at')->limit(200)->get(),
            'render_failures' => RenderFailureQueue::query()->where('operator_code', $operator)
                ->whereIn('status', [RenderFailureQueue::PENDING_RETRY, RenderFailureQueue::GAVE_UP_AUTO])
                ->orderByDesc('last_attempt_at')->limit(200)->get(),
        ]);
    }

    /** POST /api/admin/notifications/render-failures/{renderFailureQueue}/retry — admin re-render (O-5). */
    public function retryRender(Request $request, RenderFailureQueue $renderFailureQueue): JsonResponse
    {
        return ApiResponse::item($this->renderRetry->attempt($renderFailureQueue));
    }

    /** POST /api/admin/notifications/routing/pause — pause/resume routing for an event (O-6). */
    public function pauseRouting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string'],
            'channel' => ['nullable', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);
        $count = NotificationRoutingRule::query()
            ->where('operator_code', Context::operatorCode())
            ->where('event_type', $data['event_type'])
            ->when($data['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->update(['enabled' => $data['enabled']]);

        return ApiResponse::item(['updated' => $count, 'enabled' => $data['enabled']]);
    }

    /** POST /api/admin/notifications/bounces — SMTP bounce intake (F-5). */
    public function processBounce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'type' => ['required', 'in:SOFT,HARD'],
        ]);
        $pref = $this->bounces->processBounce($data['customer_id'], $data['type']);

        return $pref ? ApiResponse::item($pref) : ApiResponse::item(['updated' => false]);
    }
}
