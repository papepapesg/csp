<?php

namespace Modules\Ilm\Events;

/** EM-03 CVM domain events (DD §7), published on the em03.cvm topic. */
final class CvmEvents
{
    public const TOPIC = 'em03.cvm';

    public const CUSTOMER_EVALUATED = 'CvmCustomerEvaluated';

    public const ACTIVITY_CREATED = 'CvmActivityCreated';

    public const ACTIVITY_CLOSED = 'CvmActivityClosed';

    public const OFFER_PROPOSED = 'CvmOfferProposed';

    public const OFFER_ACCEPTED = 'CvmOfferAccepted';

    public const OFFER_APPLIED = 'CvmOfferApplied';
}
