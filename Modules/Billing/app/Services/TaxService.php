<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Contracts\TaxGateway;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TaxInvoice;

/**
 * BIL-02-TAX-01 — issue a fiscalised tax invoice via the tax gateway.
 */
class TaxService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly TaxGateway $gateway,
    ) {}

    public function issue(Invoice $invoice): TaxInvoice
    {
        $existing = TaxInvoice::query()->where('invoice_id', $invoice->invoice_id)->where('status', 'FISCALISED')->first();
        if ($existing) {
            return $existing; // idempotent
        }

        return DB::transaction(function () use ($invoice) {
            $result = $this->gateway->fiscalize($invoice);
            if (! ($result['ok'] ?? false)) {
                throw DomainException::dependencyUnavailable('Tax gateway rejected: '.($result['error'] ?? 'unknown'));
            }

            $taxInvoice = TaxInvoice::query()->create([
                'invoice_id' => $invoice->invoice_id,
                'operator_code' => $invoice->operator_code,
                'fiscal_number' => $result['fiscalNumber'] ?? null,
                'control_code' => $result['controlCode'] ?? null,
                'gateway_ref' => $result['ref'] ?? null,
                'status' => 'FISCALISED',
                'response' => $result['response'] ?? null,
                'issued_at' => now(),
            ]);
            $invoice->update(['type' => 'TAX']);

            $this->events->publish(new DomainEvent(
                type: BillingEvents::TAX_INVOICE_ISSUED,
                topic: BillingEvents::TOPIC,
                payload: ['invoiceId' => $invoice->invoice_id, 'fiscalNumber' => $taxInvoice->fiscal_number],
                aggregateType: 'TaxInvoice',
                aggregateId: $taxInvoice->tax_invoice_id,
            ));

            return $taxInvoice;
        });
    }
}
