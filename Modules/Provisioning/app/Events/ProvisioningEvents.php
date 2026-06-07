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
}
