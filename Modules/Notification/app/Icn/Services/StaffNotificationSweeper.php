<?php

namespace Modules\Notification\Icn\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Notification\Icn\StaffNotificationEvents;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;

/**
 * ICN-01 background sweeps. retryDue() re-runs the dispatcher for notifications with due
 * FAILED/PENDING deliveries (R-ICN-01-D-7); expireWindow() moves notifications past their
 * ack window to EXPIRED, suppresses their open deliveries, and emits StaffNotificationExpired
 * (R-ICN-01-D-3). ICN-01 never escalates — the calling workflow owns that timer.
 */
class StaffNotificationSweeper
{
    public function __construct(
        private readonly DeliveryDispatcher $dispatcher,
        private readonly EventBus $events,
    ) {}

    public function retryDue(?string $operator = null, int $limit = 200): int
    {
        $notificationIds = StaffNotificationDelivery::query()
            ->whereIn('status', [StaffNotificationDelivery::FAILED, StaffNotificationDelivery::PENDING])
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->whereHas('notification', fn ($q) => $q->whereIn('status', [StaffNotification::PROCESSING, StaffNotification::DISPATCHED]))
            ->limit($limit)->pluck('notification_id')->unique();

        foreach ($notificationIds as $id) {
            $n = StaffNotification::query()->whereKey($id)->first();
            if ($n) {
                $this->dispatcher->dispatchNotification($n);
            }
        }

        return $notificationIds->count();
    }

    public function expireWindow(?string $operator = null, int $limit = 200): int
    {
        $due = StaffNotification::query()
            ->whereIn('status', [StaffNotification::PROCESSING, StaffNotification::DISPATCHED])
            ->where('expires_at', '<=', now())
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->limit($limit)->get();

        foreach ($due as $notification) {
            $notification->deliveries()
                ->whereIn('status', [StaffNotificationDelivery::PENDING, StaffNotificationDelivery::FAILED])
                ->update(['status' => StaffNotificationDelivery::SUPPRESSED, 'failure_reason' => 'ACK_WINDOW_ELAPSED', 'next_retry_at' => null]);
            $notification->update(['status' => StaffNotification::EXPIRED, 'expiry_reason' => 'ACK_WINDOW']);
            $this->events->publish(new DomainEvent(
                type: StaffNotificationEvents::EXPIRED,
                topic: StaffNotificationEvents::TOPIC,
                payload: ['notificationId' => $notification->notification_id, 'operatorCode' => $notification->operator_code, 'reason' => 'ACK_WINDOW'],
                aggregateType: 'StaffNotification',
                aggregateId: $notification->notification_id,
            ));
        }

        return $due->count();
    }
}
