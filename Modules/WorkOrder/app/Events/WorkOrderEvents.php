<?php

namespace Modules\WorkOrder\Events;

/**
 * WO-01 domain-event types + topic.
 */
final class WorkOrderEvents
{
    public const TOPIC = 'workorder.field';

    public const CREATED = 'WorkOrderCreated';

    public const ASSIGNED = 'WorkOrderAssigned';

    public const STARTED = 'WorkOrderStarted';

    public const FINALIZED = 'WorkOrderFinalized';

    public const CANCELLED = 'WorkOrderCancelled';
}
