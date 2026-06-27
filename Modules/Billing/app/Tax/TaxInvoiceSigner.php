<?php

namespace Modules\Billing\Tax;

use Modules\Billing\Tax\Models\TaxInvoice;
use Modules\Billing\Tax\Models\TaxOperatorConfig;

/**
 * BIL-02-TAX-01 pluggable signer contract (R-TAX-01-S-1/S-5). One implementation per operator
 * tax authority — it owns the gateway protocol (REST/SOAP, mTLS/OAuth/JWT, payload mapping,
 * response parsing, error interpretation). The shared framework only calls sign()/cancel() and
 * handles the abstracted result. Selected at runtime by
 * tax_operator_config.signing_service_implementation_ref.
 */
interface TaxInvoiceSigner
{
    /** Implementation code, matching signing_service_implementation_ref. */
    public function implCode(): string;

    /**
     * Submit the tax invoice to the authority. Must be deterministic so retries don't mint a
     * second signed reference (R-TAX-01-S-6).
     *
     * @throws TaxSigningException categorized TRANSIENT or VALIDATION
     */
    public function sign(TaxInvoice $taxInvoice, TaxOperatorConfig $config): TaxSignaturePayload;

    /**
     * Submit a cancellation for an already-signed tax invoice; returns the authority's
     * cancellation reference (R-TAX-01-C-1).
     *
     * @throws TaxSigningException
     */
    public function cancel(TaxInvoice $taxInvoice, string $reason, TaxOperatorConfig $config): string;
}
