<?php

namespace Modules\Notification\Icn;

/** ICN-01 domain events (§6), published on the sophix.icn.* topic namespace. */
final class StaffNotificationEvents
{
    public const TOPIC = 'sophix.icn';

    public const CREATED = 'StaffNotificationCreated';

    public const DELIVERED = 'StaffNotificationDelivered';

    public const ACKNOWLEDGED = 'StaffNotificationAcknowledged';

    public const DELIVERY_FAILED = 'StaffNotificationDeliveryFailed';

    public const FAILED_TO_REACH_ANYONE = 'StaffNotificationFailedToReachAnyone';

    public const EXPIRED = 'StaffNotificationExpired';

    public const TEMPLATE_RENDER_WARNING = 'StaffNotificationTemplateRenderWarning';
}
