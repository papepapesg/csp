<?php

namespace Modules\Notification\Icn;

/** Result of one adapter.dispatch() call (§7). success carries a provider message id; failure a reason. */
final class ChannelDispatchResult
{
    /** @param array<string,mixed> $providerResponse */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
        public readonly array $providerResponse = [],
    ) {}

    /** @param array<string,mixed> $providerResponse */
    public static function ok(string $providerMessageId, array $providerResponse = []): self
    {
        return new self(true, $providerMessageId, null, $providerResponse);
    }

    /** @param array<string,mixed> $providerResponse */
    public static function fail(string $failureReason, array $providerResponse = []): self
    {
        return new self(false, null, $failureReason, $providerResponse);
    }
}
