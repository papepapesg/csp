<?php

namespace Modules\Billing\Tax\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Support\Context;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Tax\Models\TaxOperatorConfig;
use Modules\Billing\Tax\Services\TaxInvoiceGenerator;

/**
 * BIL-02-TAX-01 trigger bridge (rule group T). Every customer payment moment fires a tax
 * invoice when the operator has tax invoicing enabled:
 *   PaymentApplied  (POSTPAID invoice payment)        -> proportional tax invoice
 *   WalletToppedUp  (PREPAID wallet topup)            -> inclusive decomposition
 *   PaymentReceived (PREPAID direct-pay, no wallet)   -> inclusive decomposition
 * Generation is idempotent on the triggering event ref, so Kafka/outbox redelivery is safe.
 */
class TaxEventBridge
{
    public function __construct(private readonly TaxInvoiceGenerator $generator) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        $payload = $event->payload ?? [];
        $operator = $event->operator_code ?? ($payload['operatorCode'] ?? null);
        if (! $operator || ! TaxOperatorConfig::enabled($operator)) {
            return; // T-4: ack and stop
        }
        Context::setOperatorCode($operator);

        match ($event->event_type) {
            'PaymentApplied' => $this->onPaymentApplied($payload),
            'WalletToppedUp' => $this->onWalletTopup($payload, $operator),
            'PaymentReceived' => $this->onPaymentReceived($payload, $operator),
            default => null,
        };
    }

    private function onPaymentApplied(array $payload): void
    {
        $invoice = isset($payload['invoiceId']) ? Invoice::query()->find($payload['invoiceId']) : null;
        if (! $invoice) {
            return;
        }
        $this->generator->fromPaymentApplied(
            $invoice,
            $payload['paymentId'] ?? 'unknown',
            (float) ($payload['applied'] ?? $payload['paidAmount'] ?? 0),
            'tax-payapplied-'.($payload['paymentId'] ?? '').'-'.$invoice->invoice_id,
        );
    }

    private function onWalletTopup(array $payload, string $operator): void
    {
        $this->generator->fromWalletTopup([
            'operatorCode' => $operator,
            'eventId' => 'tax-topup-'.($payload['paymentRef'] ?? $payload['walletId'] ?? uniqid()),
            'subscriptionId' => $payload['subscriptionId'] ?? null,
            'customerId' => $payload['customerId'] ?? null,
            'accountId' => $payload['accountId'] ?? null,
            'walletTypeCode' => $payload['walletTypeCode'] ?? null,
            'serviceCategoryCode' => $payload['serviceCategoryCode'] ?? 'INTERNET',
            'topupAmount' => (float) ($payload['amount'] ?? $payload['topupAmount'] ?? 0),
            'topupMethod' => $payload['method'] ?? null,
            'paymentRef' => $payload['paymentRef'] ?? null,
            'currency' => $payload['currency'] ?? 'KES',
        ]);
    }

    private function onPaymentReceived(array $payload, string $operator): void
    {
        $this->generator->fromDirectPay([
            'operatorCode' => $operator,
            'eventId' => 'tax-directpay-'.($payload['paymentId'] ?? uniqid()),
            'subscriptionId' => $payload['subscriptionId'] ?? null,
            'customerId' => $payload['customerId'] ?? null,
            'accountId' => $payload['accountId'] ?? null,
            'packageRef' => $payload['packageRef'] ?? null,
            'serviceCategoryCode' => $payload['serviceCategoryCode'] ?? 'INTERNET',
            'paidAmount' => (float) ($payload['paidAmount'] ?? 0),
            'paymentMethod' => $payload['paymentMethod'] ?? null,
            'targetCycleStart' => $payload['targetCycleStart'] ?? null,
            'targetCycleEnd' => $payload['targetCycleEnd'] ?? null,
        ]);
    }
}
