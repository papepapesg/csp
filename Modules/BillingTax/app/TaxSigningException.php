<?php

namespace Modules\Billing\Tax;

use Modules\Billing\Tax\Models\TaxInvoiceSigningFailure;

/**
 * Thrown by a signer when the authority rejects a submission or the gateway is unreachable.
 * The category decides the failure policy (R-TAX-01-F-1): TRANSIENT auto-retries with backoff;
 * VALIDATION parks for admin review (won't fix itself by re-submitting).
 */
class TaxSigningException extends \RuntimeException
{
    public function __construct(
        public readonly string $failureType,        // GATEWAY_TIMEOUT | GATEWAY_UNREACHABLE | VALIDATION_FAILURE | AUTH_FAILURE
        string $message = '',
        public readonly string $category = TaxInvoiceSigningFailure::TRANSIENT,
        public readonly ?string $gatewayResponseCode = null,
        public readonly ?string $gatewayResponseBody = null,
    ) {
        parent::__construct($message);
    }

    public function isTransient(): bool
    {
        return $this->category === TaxInvoiceSigningFailure::TRANSIENT;
    }
}
