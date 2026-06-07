<?php

namespace Modules\Osr\Events;

/** OSR domain-event types + topic (OSR-01 / OSR-INSTANCE-01 / PLM-CFG-06). */
final class OsrEvents
{
    public const TOPIC = 'osr.equipment';

    public const SKU_CREATED = 'EquipmentSkuCreated';

    public const STOCK_MOVED = 'StockMoved';

    public const INSTANCE_REGISTERED = 'EquipmentInstanceRegistered';

    public const INSTANCE_STATE_CHANGED = 'EquipmentInstanceStateChanged';

    // OSR-RMA-01 swap events (topic sophix.osr.rma.*).
    public const SWAP_REQUESTED = 'EquipmentSwapRequested';

    public const SWAP_REJECTED = 'EquipmentSwapRejected';

    public const SOURCE_RECOVERED = 'EquipmentSourceRecovered';

    public const SWAP_COMPLETED = 'EquipmentSwapCompleted';
}
