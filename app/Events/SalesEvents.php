<?php

namespace App\Events;

/** SALES-01 domain events (DD §10), published on the sophix.sales topic. */
final class SalesEvents
{
    public const TOPIC = 'sophix.sales';

    public const LEAD_CREATED = 'SalesLeadCreated';
    public const LEAD_ASSIGNED = 'SalesLeadAssigned';
    public const LEAD_QUALIFIED = 'SalesLeadQualified';
    public const LEAD_CONVERTED = 'SalesLeadConverted';
    public const LEAD_LOST = 'SalesLeadLost';
    public const ATTRIBUTION_RECORDED = 'SalesAttributionRecorded';
}
