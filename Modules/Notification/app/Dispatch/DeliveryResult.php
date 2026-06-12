<?php

namespace Modules\Notification\Dispatch;

/**
 * Result returned by ChannelAdapter::send(). SENT carries an optional external
 * reference (SMTP message-id, SMS DLR ref) for later correlation; FAILED carries the
 * categorized failure that drives the dispatcher's retry/fallback/escalate policy.
 * channelResponse is truncated to 4KB by the adapter before being returned.
 */
final class DeliveryResult
{
    /** @param array<string,mixed> $channelResponse */
    private function __construct(
        public readonly bool $sent,
        public readonly ?FailureCategory $category = null,
        public readonly ?string $failureDetail = null,
        public readonly ?string $externalReference = null,
        public readonly array $channelResponse = [],
    ) {}

    /** @param array<string,mixed> $channelResponse */
    public static function sent(?string $externalReference = null, array $channelResponse = []): self
    {
        return new self(true, null, null, $externalReference, self::truncate($channelResponse));
    }

    /** @param array<string,mixed> $channelResponse */
    public static function failed(FailureCategory $category, ?string $detail = null, array $channelResponse = []): self
    {
        return new self(false, $category, $detail, null, self::truncate($channelResponse));
    }

    /**
     * Truncate the raw channel response so the audit table never bloats (contract rule:
     * "Truncate channelResponse to 4KB").
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function truncate(array $response): array
    {
        $json = json_encode($response) ?: '';
        if (strlen($json) <= 4096) {
            return $response;
        }

        return ['_truncated' => true, 'preview' => substr($json, 0, 4000)];
    }
}
