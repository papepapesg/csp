<?php

namespace Modules\Notification\Http\Controllers\Icn;

use App\Foundation\Errors\DomainException;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Icn\Services\AckService;
use Modules\Notification\Icn\Services\StaffNotificationService;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;

/**
 * ICN-01 staff-notification dispatch, acknowledgement, inbox, and observability (§5).
 */
class StaffNotificationController extends ApiController
{
    public function __construct(
        private readonly StaffNotificationService $service,
        private readonly AckService $acks,
    ) {}

    /** POST /api/staff-notifications — the single ingress for all calling modules (R-D-16). */
    public function dispatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'sourceModule' => ['required', 'string', 'max:32'],
            'sourceTaskId' => ['nullable', 'string'],
            'sourceProcessInstance' => ['nullable', 'string'],
            'sourceBusinessKey' => ['nullable', 'string'],
            'candidateGroup' => ['required', 'string'],
            'templateCode' => ['required', 'string'],
            'templateVariables' => ['nullable', 'array'],
            'urgency' => ['nullable', 'in:low,medium,high'],
            'fallbackModeOverride' => ['nullable', 'in:PARALLEL,SEQUENTIAL_UNTIL_ACK,SEQUENTIAL_UNTIL_DISPATCH'],
            'deeplinkUrl' => ['nullable', 'string'],
            'ackWindowHours' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->service->dispatch($data, $request->header('Idempotency-Key'));
        $n = $result['notification'];
        $body = [
            'notificationId' => $n->notification_id,
            'expectedRecipients' => $n->expected_recipients,
            'createdAt' => $n->created_at?->toIso8601String(),
            'expiresAt' => $n->expires_at?->toIso8601String(),
            'status' => $n->status,
        ];
        if ($result['replay']) {
            $body['idempotentReplay'] = true;

            return ApiResponse::item($body); // 200 for an idempotent replay (R-D-11)
        }

        return ApiResponse::accepted(entityId: $n->notification_id, nextAction: 'AWAIT_ACK', extra: $body);
    }

    /** POST /api/staff-notifications/{staffNotification}/ack (§5.2). */
    public function ack(Request $request, StaffNotification $staffNotification): JsonResponse
    {
        $data = $request->validate([
            'recipientUserId' => ['required', 'string'],
            'ackChannel' => ['nullable', 'string'],
        ]);
        // A staff user may only ACK on their own behalf (no proxy ACK) unless they manage ICN.
        $self = $request->user()?->uid;
        if ($data['recipientUserId'] !== $self && ! $request->user()?->can('staff_notification.manage')) {
            throw DomainException::ruleRejected('NOT_RECIPIENT', 'A staff user can only acknowledge on their own behalf.');
        }

        $result = $this->acks->acknowledge($staffNotification, $data['recipientUserId'], $data['ackChannel'] ?? null);
        $n = $result['notification'];

        return ApiResponse::item([
            'notificationId' => $n->notification_id,
            'status' => $n->status,
            'acknowledgedBy' => $n->acknowledged_by,
            'acknowledgedAt' => $n->acknowledged_at?->toIso8601String(),
            'idempotentAck' => $result['idempotent'],
        ]);
    }

    /** GET /api/staff-notifications/inbox — the BO user's own pending notifications (§5.3). */
    public function inbox(Request $request): JsonResponse
    {
        $userId = $request->query('userId', $request->user()?->uid);
        $status = $request->query('status');

        $deliveries = StaffNotificationDelivery::query()
            ->where('recipient_user_id', $userId)
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->with('notification')
            ->orderByDesc('created_at')->limit((int) $request->query('limit', 20))->get();

        $items = $deliveries->map(fn (StaffNotificationDelivery $d) => [
            'notificationId' => $d->notification_id,
            'sourceModule' => $d->notification?->source_module,
            'sourceBusinessKey' => $d->notification?->source_business_key,
            'templateCode' => $d->notification?->template_code,
            'channel' => $d->channel,
            'urgency' => $d->notification?->urgency,
            'deeplinkUrl' => $d->notification?->deeplink_url,
            'deliveryStatus' => $d->status,
            'deliveredAt' => $d->last_attempt_at?->toIso8601String(),
            'expiresAt' => $d->notification?->expires_at?->toIso8601String(),
        ]);

        return ApiResponse::item([
            'notifications' => $items,
            'totalUnread' => StaffNotificationDelivery::query()->where('recipient_user_id', $userId)
                ->whereIn('status', [StaffNotificationDelivery::PENDING, StaffNotificationDelivery::DISPATCHED])->count(),
        ]);
    }

    /** GET /api/staff-notifications/{staffNotification} — full row + deliveries (ops). */
    public function show(StaffNotification $staffNotification): JsonResponse
    {
        return ApiResponse::item(['notification' => $staffNotification, 'deliveries' => $staffNotification->deliveries()->get()]);
    }

    /** GET /api/staff-notifications?source_module=&since= — audit/triage query. */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = StaffNotification::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('source_module'), fn ($q, $m) => $q->where('source_module', $m))
            ->when($request->query('candidateGroup'), fn ($q, $g) => $q->where('candidate_group', $g))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('since'), fn ($q, $s) => $q->where('created_at', '>=', $s))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/staff-notifications/stats — ack rate, channel success, mean time to ack (§5.5). */
    public function stats(Request $request): JsonResponse
    {
        $operator = $request->query('operatorCode', Context::operatorCode());
        $since = $request->query('since', now()->subDays(30)->toDateString());

        $notifs = StaffNotification::query()->where('operator_code', $operator)->where('created_at', '>=', $since);
        $total = (clone $notifs)->count();
        $acked = (clone $notifs)->where('status', StaffNotification::ACKNOWLEDGED)->count();
        $expired = (clone $notifs)->where('status', StaffNotification::EXPIRED)->count();

        $byChannel = StaffNotificationDelivery::query()->where('operator_code', $operator)->where('created_at', '>=', $since)
            ->selectRaw('channel, status, count(*) as c')->groupBy('channel', 'status')->get();

        return ApiResponse::item([
            'total' => $total,
            'acknowledged' => $acked,
            'expired' => $expired,
            'ackRate' => $total > 0 ? round($acked / $total, 3) : null,
            'deliveriesByChannelStatus' => $byChannel,
        ]);
    }
}
