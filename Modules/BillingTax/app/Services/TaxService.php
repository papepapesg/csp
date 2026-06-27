<?php

namespace Modules\Billing\Tax\Services;
use Modules\Billing\Tax\Services\TaxInvoiceGenerator;
use Modules\Billing\Tax\Services\TaxSigningService;

use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Tax\Models\TaxInvoice;
use Modules\Billing\Tax\Models\TaxOperatorConfig;

/**
 * BIL-02-TAX-01 — legacy synchronous entry point retained for the existing
 * `POST /api/invoices/{invoice}/tax-invoice` endpoint. Generates a tax invoice for the
 * invoice's full amount and signs it inline through the real generator + signing service
 * (the same path the payment-triggered flow uses), so there is one authoritative tax-invoice
 * model. The DD's primary trigger is the payment moment (see TaxEventBridge); this is the
 * manual "fiscalise this invoice now" shim.
 */
class TaxService
{
    public function __construct(
        private readonly TaxInvoiceGenerator $generator,
        private readonly TaxSigningService $signing,
    ) {}

    public function issue(Invoice $invoice): TaxInvoice
    {
        // Ensure the operator can generate (the endpoint is an explicit admin action, so
        // auto-enable a transient config when none exists rather than silently no-op).
        if (! TaxOperatorConfig::enabled($invoice->operator_code)) {
            TaxOperatorConfig::query()->updateOrCreate(['operator_code' => $invoice->operator_code], ['enabled' => true]);
        }

        $ref = 'tax-manual-invoice-'.$invoice->invoice_id;
        $existing = TaxInvoice::query()->where('operator_code', $invoice->operator_code)->where('triggering_event_ref', $ref)->first();
        $tax = $existing ?? $this->generator->fromPaymentApplied($invoice, 'manual-'.$invoice->invoice_id, (float) $invoice->total_amount, $ref);

        return $this->signing->sign($tax); // GENERATED -> SIGNED inline
    }
}
