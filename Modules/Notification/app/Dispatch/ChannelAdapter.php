<?php

namespace Modules\Notification\Dispatch;

use Modules\Notification\Models\ChannelOperatorConfig;

/**
 * NOT-01 channel adapter contract (R-NOT-01-C-1). The extension seam: adding a channel
 * (WhatsApp, push, voice, paper) is implementing ONE new adapter — routing, preferences,
 * failure handling, audit, and admin ops are all unchanged.
 *
 * Lifecycle: one instance per (channel, operator) created at resolution time, initialized
 * with the operator's channel_operator_config + resolved credentials, then reused.
 *
 * Contract obligations a new adapter MUST satisfy:
 *   - send() has no side effects beyond the channel call (no DB writes, no events — the
 *     dispatcher owns audit and emission);
 *   - categorize failures correctly into FailureCategory (a TRANSIENT marked PERMANENT
 *     loses a deliverable message; the reverse wastes 8h of retries);
 *   - respect the timeout — return a TRANSIENT failure rather than blocking;
 *   - never log PII (recipient/content) at INFO;
 *   - capture an externalReference when the channel returns one;
 *   - truncate the channel response (handled by DeliveryResult).
 */
interface ChannelAdapter
{
    /** The channel this adapter handles (EMAIL, SMS, ...). */
    public function channel(): string;

    /**
     * Validate config + prepare reusable resources (connection pool, HTTP client).
     *
     * @throws AdapterConfigurationException if config/credentials are invalid
     */
    public function initialize(ChannelOperatorConfig $config): void;

    /** Send one dispatch, returning a categorized result. $timeoutSeconds caps the wait. */
    public function send(Dispatch $dispatch, int $timeoutSeconds): DeliveryResult;

    /** Release resources at shutdown. */
    public function shutdown(): void;
}
