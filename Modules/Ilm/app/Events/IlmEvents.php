<?php

namespace Modules\Ilm\Events;

/**
 * Canonical ILM domain-event types + Kafka topic (FOUNDATION_KAFKA).
 * Published via the transactional outbox after the authoritative commit.
 */
final class IlmEvents
{
    public const TOPIC = 'ilm.customer';

    public const CUSTOMER_CREATED = 'CustomerCreated';

    public const CUSTOMER_UPDATED = 'CustomerUpdated';

    public const CUSTOMER_KYC_APPROVED = 'CustomerKycApproved';

    public const CUSTOMER_KYC_REJECTED = 'CustomerKycRejected';

    public const CUSTOMER_ACCOUNT_CREATED = 'CustomerAccountCreated';

    public const CUSTOMER_ACCOUNT_STATUS_CHANGED = 'CustomerAccountStatusChanged';

    public const ACCOUNT_FLAG_SET = 'CustomerAccountFlagSet';

    public const ACCOUNT_FLAG_CLEARED = 'CustomerAccountFlagCleared';
}
