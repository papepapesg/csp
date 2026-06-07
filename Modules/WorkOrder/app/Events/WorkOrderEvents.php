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

    // WO-01-FLOW-SUPPORT events.
    public const SUPPORT_COMPLETED = 'WorkOrderSupportCompleted';

    public const ESCALATION_CANDIDATE = 'WorkOrderEscalationCandidate';

    public const RPT_LINKAGE_RECORDED = 'WorkOrderRPTLinkageRecorded';

    public const EQUIPMENT_BINDINGS_RECORDED = 'WorkOrderEquipmentBindingsRecorded';

    // WO-01-FLOW-SHIFTING events.
    public const PHASE_TRANSITIONED = 'WorkOrderPhaseTransitioned';

    public const SHIFTING_COMPLETED = 'WorkOrderShiftingCompleted';
}
