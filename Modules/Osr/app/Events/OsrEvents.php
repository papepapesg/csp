<?php

namespace Modules\Osr\Events;

/** OSR domain-event types + topic (OSR-01 / OSR-INSTANCE-01 / PLM-CFG-06). */
final class OsrEvents
{
    public const TOPIC = 'osr.equipment';

    public const SKU_CREATED = 'EquipmentSkuCreated';

    public const STOCK_MOVED = 'StockMoved';

    // OSR-01 §1.5 reservation lifecycle.
    public const STOCK_RESERVED = 'StockReserved';

    public const STOCK_RESERVATION_CONSUMED = 'StockReservationConsumed';

    public const STOCK_RESERVATION_RELEASED = 'StockReservationReleased';

    public const STOCK_RESERVATION_EXPIRED = 'StockReservationExpired';

    public const INSTANCE_REGISTERED = 'EquipmentInstanceRegistered';

    public const INSTANCE_STATE_CHANGED = 'EquipmentInstanceStateChanged';

    // OSR-INSTANCE-01 §6 named lifecycle events (specific consumers downstream).
    public const INSTANCE_RECOVERED_BY_CONTRACTOR = 'EquipmentInstanceRecoveredByContractor';

    public const INSTANCE_BOUND_TO_CUSTOMER = 'EquipmentInstanceBoundToCustomer';

    public const INSTANCE_UNBOUND_FROM_CUSTOMER = 'EquipmentInstanceUnboundFromCustomer';

    public const INSTANCE_DECOMMISSIONED = 'EquipmentInstanceDecommissioned';

    // OSR-RMA-01 swap events (topic sophix.osr.rma.*).
    public const SWAP_REQUESTED = 'EquipmentSwapRequested';

    public const SWAP_REJECTED = 'EquipmentSwapRejected';

    public const SOURCE_RECOVERED = 'EquipmentSourceRecovered';

    public const SWAP_COMPLETED = 'EquipmentSwapCompleted';
}
