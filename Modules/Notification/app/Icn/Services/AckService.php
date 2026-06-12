<?php

namespace Modules\Notification\Icn\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Icn\StaffNotificationEvents;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;

/**
 * ICN-01 acknowledgement (§5.2, R-ICN-01-D-1/2). The first ACK from ANY recipient on ANY of
 * their deliveries flips the parent to ACKNOWLEDGED and SUPPRESSES every still-undispatched
 * delivery (we don't pester the rest of the group once the task is in flight). A staff user
 * may only ACK on their own behalf; a second ACK is an idempotent no-op.
 */
class AckService
{
    public function __construct(private readonly EventBus $events) {}

    /** @return array{notification:StaffNotification, idempotent:bool} */
    public function acknowledge(StaffNotification $notification, string $recipientUserId, ?string $ackChannel = null): array
    {
        if ($notification->status === StaffNotification::ACKNOWLEDGED) {
            return ['notification' => $notification, 'idempotent' => true];
        }

        $delivery = $notification->deliveries()
            ->where('recipient_user_id', $recipientUserId)
            ->when($ackChannel, fn ($q) => $q->where('channel', $ackChannel))
            ->first();
        if (! $delivery) {
            throw DomainException::ruleRejected('NOT_RECIPIENT', 'User is not a recipient of this notification.');
        }

        return DB::transaction(function () use ($notification, $recipientUserId, $delivery) {
            $delivery->update(['status' => StaffNotificationDelivery::ACKNOWLEDGED, 'acknowledged_at' => now()]);
            $notification->update([
                'status' => StaffNotification::ACKNOWLEDGED,
                'acknowledged_by' => $recipientUserId,
                'acknowledged_at' => now(),
            ]);

            // R-D-2: in-flight (not-yet-dispatched) deliveries move to SUPPRESSED.
            $notification->deliveries()
                ->whereIn('status', [StaffNotificationDelivery::PENDING, StaffNotificationDelivery::FAILED])
                ->update(['status' => StaffNotificationDelivery::SUPPRESSED, 'failure_reason' => 'ACKNOWLEDGED_ELSEWHERE', 'next_retry_at' => null]);

            $this->events->publish(new DomainEvent(
                type: StaffNotificationEvents::ACKNOWLEDGED,
                topic: StaffNotificationEvents::TOPIC,
                payload: [
                    'notificationId' => $notification->notification_id,
                    'operatorCode' => $notification->operator_code,
                    'sourceModule' => $notification->source_module,
                    'candidateGroup' => $notification->candidate_group,
                    'acknowledgedBy' => $recipientUserId,
                    'ackChannel' => $delivery->channel,
                    'timeToAckSeconds' => $notification->created_at?->diffInSeconds(now()),
                ],
                aggregateType: 'StaffNotification',
                aggregateId: $notification->notification_id,
            ));

            return ['notification' => $notification->refresh(), 'idempotent' => false];
        });
    }
}
