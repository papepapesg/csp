<?php

namespace Modules\Billing\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Support\Context;
use Modules\Billing\Services\PaymentService;

/**
 * BIL-01-PAY-01 R-PAY-01-OV-2: when GEN-01 issues an invoice for an account that
 * holds a positive credit balance, PAY-01 auto-applies the credit as a synthetic
 * payment (APPLY_TO_NEXT_OPEN overpayment policy).
 */
class ApplyCreditBalanceOnInvoice
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'InvoiceGenerated') {
            return;
        }
        $invoiceId = $event->payload['invoiceId'] ?? null;
        if (! $invoiceId) {
            return;
        }
        Context::setOperatorCode($event->operator_code ?? Context::operatorCode());
        $this->payments->applyCreditBalanceToInvoice($invoiceId);
    }
}
