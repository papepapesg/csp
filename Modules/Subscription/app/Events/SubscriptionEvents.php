<?php

namespace Modules\Subscription\Events;

/**
 * Subscription domain-event types + topic (SUB-LM / SUB-WF).
 */
final class SubscriptionEvents
{
    public const TOPIC = 'subscription.lifecycle';

    public const CREATED = 'SubscriptionCreated';

    public const ACTIVATED = 'SubscriptionActivated';

    public const SUSPENDED = 'SubscriptionSuspended';

    public const PAUSED = 'SubscriptionPaused';

    public const RESUMED = 'SubscriptionResumed';

    public const TERMINATED = 'SubscriptionTerminated';

    public const STATUS_CHANGED = 'SubscriptionStatusChanged';

    public const OPERATION_STARTED = 'SubscriptionOperationStarted';

    public const OPERATION_COMPLETED = 'SubscriptionOperationCompleted';

    public const OPERATION_FAILED = 'SubscriptionOperationFailed';

    // SUB-WF-RESTRICT-01 partial-service restriction events.
    public const RESTRICTION_ADDED = 'SubscriptionRestrictionAdded';

    public const RESTRICTION_REMOVED = 'SubscriptionRestrictionRemoved';

    public const RESTRICTION_ADD_REJECTED = 'SubscriptionRestrictionAddRejected';

    public const RESTRICTION_REMOVE_REJECTED = 'SubscriptionRestrictionRemoveRejected';
}
