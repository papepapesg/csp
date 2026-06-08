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

    // SUB-WF-SUSPEND-NP-01 §6: non-payment suspension uses its own event so consumers
    // (BIL-04, CVM, finance write-off) can distinguish it from a voluntary pause.
    public const SUSPENDED_NON_PAYMENT = 'SubscriptionSuspendedForNonPayment';

    public const SUSPEND_NP_REJECTED = 'SubscriptionSuspendNPRejected';

    public const PAUSED = 'SubscriptionPaused';

    public const RESUMED = 'SubscriptionResumed';

    public const TERMINATED = 'SubscriptionTerminated';

    public const STATUS_CHANGED = 'SubscriptionStatusChanged';

    public const OPERATION_STARTED = 'SubscriptionOperationStarted';

    public const OPERATION_COMPLETED = 'SubscriptionOperationCompleted';

    public const OPERATION_FAILED = 'SubscriptionOperationFailed';

    public const OPERATION_CANCELLED = 'SubscriptionOperationCancelled';

    // SUB-WF-UPGRADE-01 / DOWNGRADE-01 package-change events.
    public const UPGRADED = 'SubscriptionUpgraded';

    public const UPGRADE_REJECTED = 'SubscriptionUpgradeRejected';

    public const DOWNGRADED = 'SubscriptionDowngraded';

    public const DOWNGRADE_REJECTED = 'SubscriptionDowngradeRejected';

    // SUB-WF-RELOCATION-01 / MIGRATION-01 events.
    public const RELOCATED = 'SubscriptionRelocated';

    public const RELOCATION_REJECTED = 'SubscriptionRelocationRejected';

    public const MIGRATED = 'SubscriptionMigrated';

    public const MIGRATION_REJECTED = 'SubscriptionMigrationRejected';

    // SUB-WF-RESTRICT-01 partial-service restriction events.
    public const RESTRICTION_ADDED = 'SubscriptionRestrictionAdded';

    public const RESTRICTION_REMOVED = 'SubscriptionRestrictionRemoved';

    public const RESTRICTION_ADD_REJECTED = 'SubscriptionRestrictionAddRejected';

    public const RESTRICTION_REMOVE_REJECTED = 'SubscriptionRestrictionRemoveRejected';
}
