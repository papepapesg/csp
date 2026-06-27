<?php

namespace Modules\Billing\Tax\Signers;

use Modules\Billing\Tax\Models\TaxInvoice;
use Modules\Billing\Tax\Models\TaxInvoiceSigningFailure;
use Modules\Billing\Tax\Models\TaxOperatorConfig;
use Modules\Billing\Tax\TaxInvoiceSigner;
use Modules\Billing\Tax\TaxSignaturePayload;
use Modules\Billing\Tax\TaxSigningException;

/**
 * Default in-process signer (the deployment stub). Signs deterministically — the signed
 * reference is derived from the tax invoice id, so a retry of the same invoice yields the same
 * reference (S-6). A real authority integration (KRA eTIMS, Senegal DGID, ...) implements this
 * same contract and is selected per operator via config.
 *
 * Failure injection (exercises the retry/validation/give-up paths) via the customer snapshot's
 * tax_identifier sentinel:
 *   "TIMEOUT"  -> TRANSIENT GATEWAY_TIMEOUT
 *   "INVALID"  -> VALIDATION VALIDATION_FAILURE (parks for admin)
 *   "AUTHFAIL" -> TRANSIENT AUTH_FAILURE
 */
class StubTaxSigner implements TaxInvoiceSigner
{
    public function implCode(): string
    {
        return 'stub';
    }

    public function sign(TaxInvoice $taxInvoice, TaxOperatorConfig $config): TaxSignaturePayload
    {
        $pin = $taxInvoice->customer_snapshot['tax_identifier'] ?? null;
        if ($pin === 'TIMEOUT') {
            throw new TaxSigningException('GATEWAY_TIMEOUT', 'gateway did not respond', TaxInvoiceSigningFailure::TRANSIENT, '504');
        }
        if ($pin === 'AUTHFAIL') {
            throw new TaxSigningException('AUTH_FAILURE', 'certificate expired', TaxInvoiceSigningFailure::TRANSIENT, '401');
        }
        if ($pin === 'INVALID') {
            throw new TaxSigningException('VALIDATION_FAILURE', 'customer PIN not registered', TaxInvoiceSigningFailure::VALIDATION, '400', 'PIN_NOT_FOUND');
        }

        // Deterministic signed reference (idempotent across retries).
        $ref = 'SIG-'.strtoupper(substr(sha1($taxInvoice->tax_invoice_id), 0, 16));

        return new TaxSignaturePayload(
            signedInvoiceNumber: $ref,
            qrSignatureData: 'https://verify.tax.local/'.$ref,
            signedAt: now()->toIso8601String(),
            raw: ['signedReference' => $ref, 'authority' => 'STUB', 'simulated' => true],
        );
    }

    public function cancel(TaxInvoice $taxInvoice, string $reason, TaxOperatorConfig $config): string
    {
        return 'CANCEL-'.strtoupper(substr(sha1($taxInvoice->tax_invoice_id.'|cancel'), 0, 16));
    }
}
