<?php

namespace Modules\Provisioning\Events;

/** PROV-INT-01 domain-event types + topic. */
final class ProvisioningEvents
{
    public const TOPIC = 'provisioning.command';

    public const COMMAND_SENT = 'ProvisioningCommandSent';

    public const COMMAND_CONFIRMED = 'ProvisioningCommandConfirmed';

    public const COMMAND_FAILED = 'ProvisioningCommandFailed';

    public const RECONCILE_MISMATCH = 'ProvisioningReconcileMismatch';

    public const RECONCILE_RUN_COMPLETED = 'ProvisioningReconciliationRunCompleted';

    public const RECONCILE_ITEM_OPENED = 'ProvisioningReconciliationItemOpened';

    public const FORCE_SYNC_REQUESTED = 'ProvisioningForceSyncRequested';

    public const FORCE_SYNC_APPROVED = 'ProvisioningForceSyncApproved';

    public const FORCE_SYNC_COMPLETED = 'ProvisioningForceSyncCompleted';

    public const FORCE_SYNC_CANCELLED = 'ProvisioningForceSyncCancelled';
}
