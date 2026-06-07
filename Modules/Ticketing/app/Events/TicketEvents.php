<?php

namespace Modules\Ticketing\Events;

/** TCK-01 domain-event types + topic. */
final class TicketEvents
{
    public const TOPIC = 'ticketing.case';

    public const CREATED = 'TicketCreated';

    public const ASSIGNED = 'TicketAssigned';

    public const STATUS_CHANGED = 'TicketStatusChanged';

    public const RESOLVED = 'TicketResolved';

    public const CLOSED = 'TicketClosed';

    public const WORK_ORDER_LINKED = 'TicketWorkOrderLinked';
}
