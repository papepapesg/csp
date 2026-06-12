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

    // NOT-01 full-model events (rule groups D / A).
    public const PDF_READY = 'PdfReady';

    public const RENDER_FAILED = 'RenderFailed';

    public const DISPATCHED = 'NotificationDispatched';

    public const SUPPRESSED = 'NotificationSuppressed';

    public const ESCALATED = 'NotificationEscalated';

    public const UNDELIVERABLE = 'NotificationUndeliverable';

    public const BOUNCE_PROCESSED = 'BounceProcessed';
}
