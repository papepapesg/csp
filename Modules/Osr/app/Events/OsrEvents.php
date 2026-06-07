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
}
