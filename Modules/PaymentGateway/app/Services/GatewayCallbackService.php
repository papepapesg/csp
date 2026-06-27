<?php

namespace Modules\PaymentGateway\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Billing\Payments\Services\PaymentService;
use Modules\Ilm\Models\CustomerAccount;
use Modules\PaymentGateway\Events\PaymentGatewayEvents;
use Modules\PaymentGateway\Models\PaymentGatewayCallback;
use Throwable;

/**
 * PAY-GW-01 callback handling. Validates + dedupes the inbound gateway callback,
 * resolves the billing account (via ILM payment_account_number), then ROUTES the
 * money by billing mode (PAY-GW-01 §3): a POSTPAID account's payment is applied
 * to invoices (BIL-01-PAY-01); a PREPAID subscription's money is a wallet top-up
 * (BIL-05). PAY-GW owns neither ledger — it is an integration adapter.
 */
class GatewayCallbackService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string,mixed>  $data  provider, external_ref, account_ref, amount, currency?, raw?
     */
    public function handle(string $provider, array $data): PaymentGatewayCallback
    {
        // Dedupe on (provider, external_ref) — gateways retry callbacks.
        $existing = PaymentGatewayCallback::query()
            ->where('provider', $provider)
            ->where('external_ref', $data['external_ref'])
            ->first();
        if ($existing) {
            $this->emit(PaymentGatewayEvents::CALLBACK_DUPLICATE, $existing);

            return $existing;
        }

        $callback = PaymentGatewayCallback::query()->create([
            'provider' => $provider,
            'external_ref' => $data['external_ref'],
            'account_ref' => $data['account_ref'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'KES',
            'raw' => $data['raw'] ?? $data,
            'status' => PaymentGatewayCallback::RECEIVED,
        ]);
        $this->emit(PaymentGatewayEvents::CALLBACK_RECEIVED, $callback);

        try {
            $accountId = $this->resolveAccount($data['account_ref'] ?? null);
            if (! $accountId) {
                $callback->update(['status' => PaymentGatewayCallback::REJECTED, 'reject_reason' => 'ACCOUNT_NOT_FOUND']);
                $this->emit(PaymentGatewayEvents::CALLBACK_REJECTED, $callback);

                return $callback;
            }

            // PAY-01 receive owns billing-mode routing (PREPAID→wallet, POSTPAID→
            // invoices) and idempotency; the gateway just hands money to it.
            $payment = $this->payments->receiveAndApply([
                'account_id' => $accountId,
                'paid_amount' => $data['amount'],
                'method' => $provider,
                'currency' => $data['currency'] ?? 'KES',
                'gateway_ref' => $data['external_ref'],
                'payment_reference' => $data['external_ref'],
            ]);

            $callback->update([
                'status' => PaymentGatewayCallback::PROCESSED,
                'resolved_account_id' => $accountId,
                'payment_id' => $payment->payment_id,
            ]);
            $this->emit(PaymentGatewayEvents::CALLBACK_PROCESSED, $callback);

            return $callback->refresh();
        } catch (Throwable $e) {
            $callback->update(['status' => PaymentGatewayCallback::REJECTED, 'reject_reason' => $e->getMessage()]);
            $this->emit(PaymentGatewayEvents::CALLBACK_REJECTED, $callback);

            return $callback;
        }
    }

    /**
     * Resolve a billing account_id from the gateway account reference. Tries the
     * ILM payment_account_number (paybill), then the raw account_id.
     */
    private function resolveAccount(?string $accountRef): ?string
    {
        if (! $accountRef) {
            return null;
        }

        $account = CustomerAccount::query()
            ->where('payment_account_number', $accountRef)
            ->orWhere('account_id', $accountRef)
            ->first();

        return $account?->account_id ?? (str_starts_with($accountRef, 'acct_') ? $accountRef : null);
    }

    private function emit(string $type, PaymentGatewayCallback $callback): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: PaymentGatewayEvents::TOPIC,
            payload: [
                'callbackId' => $callback->callback_id,
                'provider' => $callback->provider,
                'externalRef' => $callback->external_ref,
                'status' => $callback->status,
                'paymentId' => $callback->payment_id,
            ],
            aggregateType: 'PaymentGatewayCallback',
            aggregateId: $callback->callback_id,
        ));
    }
}
