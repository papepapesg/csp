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
    ) {}

    /** @param array<string,mixed> $response */
    public static function confirmed(string $externalRef, array $response = []): self
    {
        return new self(true, $externalRef, $response);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, [], $error);
    }
}
