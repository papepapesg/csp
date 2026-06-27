<?php

namespace Modules\Billing\Tax;

/**
 * Normalized signing response (R-TAX-01-S-3): the authority's signed reference, QR data, and
 * raw payload. Each signer parses its gateway's proprietary response into this shape; the
 * shared framework knows nothing gateway-specific.
 */
final class TaxSignaturePayload
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly string $signedInvoiceNumber,
        public readonly ?string $qrSignatureData = null,
        public readonly ?string $signedAt = null,
        public readonly array $raw = [],
    ) {}
}
