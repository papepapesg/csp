<?php

namespace Modules\Notification\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Notification\Events\NotificationEvents;
use Modules\Notification\Models\CustomerNotificationPreference;

/**
 * NOT-01 email bounce processing (R-NOT-01-F-5). An SMTP bounce handler feeds bounces
 * here: a soft bounce latches email_status=SOFT_BOUNCED (keep trying, watch closely); a
 * hard bounce latches INVALID (the preference filter then skips the email channel until an
 * admin updates the address). Emits BounceProcessed for analytics.
 */
class BounceService
{
    public function __construct(private readonly EventBus $events) {}

    public function processBounce(string $customerId, string $type): ?CustomerNotificationPreference
    {
        $pref = CustomerNotificationPreference::forCustomer($customerId);
        if (! $pref) {
            return null;
        }
        $status = strtoupper($type) === 'HARD'
            ? CustomerNotificationPreference::EMAIL_INVALID
            : CustomerNotificationPreference::EMAIL_SOFT_BOUNCED;
        $pref->update(['email_status' => $status]);

        $this->events->publish(new DomainEvent(
            type: NotificationEvents::BOUNCE_PROCESSED,
            topic: NotificationEvents::TOPIC,
            payload: ['customerId' => $customerId, 'emailStatus' => $status, 'bounceType' => strtoupper($type)],
            aggregateType: 'CustomerNotificationPreference',
            aggregateId: $pref->id,
        ));

        return $pref->refresh();
    }
}
