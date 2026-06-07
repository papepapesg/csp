<?php

namespace Modules\Subscription\Workflows;

use Modules\Subscription\Models\SubscriptionOperation;

/**
 * A SUB-WF subscription operation workflow (native Camunda driver).
 *
 * Implementations orchestrate one operation kind (ACTIVATE, TERMINATE, ...).
 * handle() runs inside a queued worker against the operation's subscription and
 * drives the SUB-LM status via SubscriptionService.
 */
interface SubscriptionWorkflow
{
    /** Operation kind this workflow handles, e.g. ACTIVATE. */
    public function kind(): string;

    /** Final subscription status the operation drives to on success. */
    public function handle(SubscriptionOperation $operation): string;
}
