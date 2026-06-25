<?php

namespace Modules\PaymentGateway\Console;

use Illuminate\Console\Command;
use Modules\PaymentGateway\Models\PaymentGatewayCallback;

/**
 * Ops review: show one inbound gateway callback record (read-only) — its provider,
 * external reference, resolved account, applied payment and reject reason, so support
 * can see exactly how a single PAY-GW-01 callback was (or was not) routed to BIL
 * before touching anything.
 */
class CallbackShowCommand extends Command
{
    protected $signature = 'sophix:paymentgateway:callback-show {callback : The callback_id (pgcb_...)}';

    protected $description = 'Review: show one payment-gateway callback (read-only)';

    public function handle(): int
    {
        $callbackId = (string) $this->argument('callback');
        $callback = PaymentGatewayCallback::query()->where('callback_id', $callbackId)->first();
        if (! $callback) {
            $this->warn("No payment-gateway callback found for {$callbackId}.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['callback_id', $callback->callback_id],
            ['operator_code', $callback->operator_code],
            ['provider', $callback->provider],
            ['external_ref', $callback->external_ref],
            ['account_ref', $callback->account_ref ?? '—'],
            ['resolved_account_id', $callback->resolved_account_id ?? '—'],
            ['amount', $callback->amount.' '.($callback->currency ?? '')],
            ['status', $callback->status],
            ['payment_id', $callback->payment_id ?? '—'],
            ['reject_reason', $callback->reject_reason ?? '—'],
            ['received_at', (string) $callback->received_at],
            ['created_at', (string) $callback->created_at],
        ]);

        if ($callback->status === PaymentGatewayCallback::REJECTED) {
            $this->warn("Status REJECTED — no payment was applied (reason: {$callback->reject_reason}).");
        } elseif ($callback->status === PaymentGatewayCallback::DUPLICATE) {
            $this->warn('Status DUPLICATE — a gateway retry of an already-seen (provider, external_ref).');
        }

        return self::SUCCESS;
    }
}
