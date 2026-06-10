<?php

namespace Modules\Provisioning\Contracts;

/** Result of dispatching one provisioning command to a target. */
class ProvisioningResult
{
    /** @param array<string,mixed> $response */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $externalRef = null,
        public readonly array $response = [],
        public readonly ?string $error = null,
        public readonly bool $async = false,        // PROV §7.2: vendor accepted, result pending
        public readonly bool $finalFailure = false, // FAILED_FINAL vs FAILED_RETRYABLE
    ) {}

    /** @param array<string,mixed> $response */
    public static function confirmed(string $externalRef, array $response = []): self
    {
        return new self(true, $externalRef, $response);
    }

    /** PROV §7.2 async: the vendor accepted the command; a status worker resolves it later. */
    public static function accepted(string $externalRef, array $response = []): self
    {
        return new self(true, $externalRef, $response, async: true);
    }

    public static function failed(string $error, bool $final = false): self
    {
        return new self(false, null, [], $error, finalFailure: $final);
    }
}
