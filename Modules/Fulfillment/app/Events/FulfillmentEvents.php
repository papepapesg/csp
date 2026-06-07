<?php

namespace Modules\Fulfillment\Events;

/** FUL-02/03 domain-event types + topic. */
final class FulfillmentEvents
{
    public const TOPIC = 'fulfillment.order';

    public const ORDER_CAPTURED = 'OrderCaptured';

    public const ORDER_STEP_COMPLETED = 'OrderStepCompleted';

    public const ORDER_COMPLETED = 'OrderCompleted';

    public const ORDER_CANCELLED = 'OrderCancelled';
}
