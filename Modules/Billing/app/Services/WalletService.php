<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\Wallet;
use Modules\Billing\Models\WalletTransaction;
use Modules\Catalog\Models\WalletCatalog;

/**
 * BIL-05/BIL-06 wallet & top-up engine. Owns the per-customer wallet balance and an
 * append-only transaction ledger. A subscription may hold several wallets, one per
 * PLM-CFG-03 catalog `walletRef` (MONEY_KES, VOICE_KES, …); behaviour (currency,
 * applicability, charging precedence, refillability) comes from the catalog — this
 * service applies those rules to the customer's balance.
 */
class WalletService
{
    /** Default money wallet code convention when a caller does not name one. */
    public const DEFAULT_WALLET_CODE = 'MONEY_KES';

    public function __construct(private readonly EventBus $events) {}

    /**
     * Resolve (or create) the ledger row for a (subscription, walletRef). The
     * walletRef must resolve to an ACTIVE PLM-CFG-03 catalog entry — the catalog is
     * the source of truth for what wallets exist and their currency (R-W-3).
     */
    public function ensureWallet(
        string $subscriptionId,
        string $walletCode = self::DEFAULT_WALLET_CODE,
        ?string $accountId = null,
        ?string $customerId = null,
    ): Wallet {
        $catalog = WalletCatalog::activeByCode(Context::operatorCode(), $walletCode);
        if (! $catalog) {
            throw DomainException::ruleRejected(
                'UNKNOWN_WALLET_REF',
                "No ACTIVE wallet '{$walletCode}' in the PLM-CFG-03 catalog for this operator.",
            );
        }

        return Wallet::query()->firstOrCreate(
            ['subscription_id' => $subscriptionId, 'wallet_code' => $walletCode],
            [
                'operator_code' => Context::operatorCode(),
                'account_id' => $accountId,
                'customer_id' => $customerId,
                'currency' => $catalog->currency,
                'balance' => 0,
                'status' => 'ACTIVE',
            ],
        );
    }

    /**
     * Charge-time wallet selection (PLM-CFG-03 §charging): the eligible ACTIVE
     * wallets the subscription holds, filtered by `applicability` against the
     * billing mode and ordered by `charging_precedence` (lower applied first).
     *
     * @return Collection<int,Wallet>
     */
    public function resolveChargingWallets(string $subscriptionId, string $billingMode = 'PREPAID', ?string $operator = null): Collection
    {
        $operator ??= Context::operatorCode();
        $catalog = WalletCatalog::query()
            ->where('operator_code', $operator)
            ->where('status', WalletCatalog::STATUS_ACTIVE)
            ->get()
            ->keyBy('code');

        return Wallet::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'ACTIVE')
            ->get()
            ->filter(fn (Wallet $w) => $catalog->has($w->wallet_code)
                && $catalog[$w->wallet_code]->appliesToBillingMode($billingMode))
            ->sortBy(fn (Wallet $w) => $catalog[$w->wallet_code]->charging_precedence)
            ->values();
    }

    /**
     * Settle a positive charge from the subscription's prepaid wallets, draining by
     * charging_precedence (lowest first). Atomic: only debits when the eligible
     * balance fully covers the amount — a partial balance settles nothing (the
     * caller parks/tops up). Returns whether it settled and how much was debited.
     *
     * @return array{settled:bool, debited:float, available:float}
     */
    public function settleFromWallets(string $subscriptionId, float $amount, string $reason, ?string $operator = null, ?string $reference = null): array
    {
        $amount = round($amount, 2);
        $wallets = $this->resolveChargingWallets($subscriptionId, 'PREPAID', $operator);
        $available = (float) $wallets->sum(fn (Wallet $w) => (float) $w->balance);

        if ($available + 0.0001 < $amount) {
            return ['settled' => false, 'debited' => 0.0, 'available' => $available];
        }

        $remaining = $amount;
        foreach ($wallets as $wallet) {
            if ($remaining <= 0.0001) {
                break;
            }
            $take = min($remaining, (float) $wallet->balance);
            if ($take <= 0) {
                continue;
            }
            $this->debit($wallet, $take, $reason, $reference);
            $remaining -= $take;
        }

        return ['settled' => true, 'debited' => $amount, 'available' => $available];
    }

    public function credit(Wallet $wallet, float $amount, string $reason = 'TOPUP', ?string $reference = null): WalletTransaction
    {
        // R-W-11: a non-refillable wallet rejects top-ups (one-shot promo/bonus credits).
        if ($reason === 'TOPUP') {
            $catalog = WalletCatalog::activeByCode($wallet->operator_code, (string) $wallet->wallet_code);
            if ($catalog && ! $catalog->refillable) {
                throw DomainException::ruleRejected(
                    'WALLET_NOT_REFILLABLE',
                    "Wallet {$wallet->wallet_code} does not accept top-ups.",
                );
            }
        }

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
                payload: ['walletId' => $wallet->wallet_id, 'walletCode' => $wallet->wallet_code, 'subscriptionId' => $wallet->subscription_id, 'amount' => (string) $amount, 'balance' => (string) $newBalance, 'reason' => $reason],
                aggregateType: 'Wallet',
                aggregateId: $wallet->wallet_id,
            ));

            return $txn;
        });
    }
}
