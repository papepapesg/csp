<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\Wallet;
use Modules\Billing\Models\WalletTransaction;

/**
 * BIL-05 wallet & top-up engine for PREPAID subscriptions. Owns the wallet
 * balance and an append-only transaction ledger.
 */
class WalletService
{
    public function __construct(private readonly EventBus $events) {}

    public function ensureWallet(string $subscriptionId, ?string $accountId = null, string $currency = 'KES'): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['subscription_id' => $subscriptionId],
            [
                'operator_code' => Context::operatorCode(),
                'account_id' => $accountId,
                'currency' => $currency,
                'balance' => 0,
                'status' => 'ACTIVE',
            ],
        );
    }

    public function credit(Wallet $wallet, float $amount, string $reason = 'TOPUP', ?string $reference = null): WalletTransaction
    {
        return $this->post($wallet, 'CREDIT', $amount, $reason, $reference);
    }

    public function debit(Wallet $wallet, float $amount, string $reason = 'CYCLE_CHARGE', ?string $reference = null): WalletTransaction
    {
        if ((float) $wallet->balance < $amount) {
            throw DomainException::ruleRejected(
                'WALLET_INSUFFICIENT_FUNDS',
                'Wallet balance is insufficient for this debit.',
                nextAction: 'TOPUP_WALLET',
            );
        }

        return $this->post($wallet, 'DEBIT', $amount, $reason, $reference);
    }

    private function post(Wallet $wallet, string $direction, float $amount, string $reason, ?string $reference): WalletTransaction
    {
        if ($amount <= 0) {
            throw DomainException::ruleRejected('INVALID_AMOUNT', 'Amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $direction, $amount, $reason, $reference) {
            $wallet = Wallet::query()->whereKey($wallet->wallet_id)->lockForUpdate()->first();
            $newBalance = (float) $wallet->balance + ($direction === 'CREDIT' ? $amount : -$amount);
            $wallet->update(['balance' => $newBalance]);

            $txn = $wallet->transactions()->create([
                'direction' => $direction,
                'reason' => $reason,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference' => $reference,
            ]);

            $type = match (true) {
                $reason === 'TOPUP' => BillingEvents::WALLET_TOPPED_UP,
                $direction === 'CREDIT' => BillingEvents::WALLET_CREDITED,
                default => BillingEvents::WALLET_DEBITED,
            };

            $this->events->publish(new DomainEvent(
                type: $type,
                topic: BillingEvents::TOPIC,
                payload: ['walletId' => $wallet->wallet_id, 'amount' => (string) $amount, 'balance' => (string) $newBalance, 'reason' => $reason],
                aggregateType: 'Wallet',
                aggregateId: $wallet->wallet_id,
            ));

            return $txn;
        });
    }
}
