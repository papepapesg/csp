<?php

namespace Modules\Notification\Events;

/** NOT-01 / ICN-01 domain-event types + topic. */
final class NotificationEvents
{
    public const TOPIC = 'notification.delivery';

    public const QUEUED = 'NotificationQueued';

    public const SENT = 'NotificationSent';

    public const FAILED = 'NotificationFailed';

    public const INTERNAL_POSTED = 'InternalMessagePosted';
}
