<?php

namespace Modules\Notification\Dispatch;

/**
 * NOT-01 failure taxonomy (R-NOT-01-F-1). The category drives the failure policy:
 *   TRANSIENT               -> retry with exponential backoff (F-2)
 *   PERMANENT_RECIPIENT     -> fall back to the next channel in priority order (F-3)
 *   PERMANENT_TEMPLATE      -> disable the template, escalate (F-4)
 *   PERMANENT_BUSINESS_RULE -> recipient blocked at carrier/region; no retry, no fallback
 */
enum FailureCategory: string
{
    case TRANSIENT = 'TRANSIENT';
    case PERMANENT_RECIPIENT = 'PERMANENT_RECIPIENT';
    case PERMANENT_TEMPLATE = 'PERMANENT_TEMPLATE';
    case PERMANENT_BUSINESS_RULE = 'PERMANENT_BUSINESS_RULE';

    public function isRetryable(): bool
    {
        return $this === self::TRANSIENT;
    }
}
