<?php

namespace Modules\Billing\Wallet\Services;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Errors\DomainException;
use App\Foundation\Models\OperatorConfig;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Wallet\Models\Wallet;
use Modules\Billing\Wallet\Models\WalletTransaction;
use Modules\Billing\Wallet\Models\WalletType;

/**
 * BIL-05/BIL-06 wallet & top-up engine. Owns the per-customer wallet balance and an
 * append-only transaction ledger. A subscription may hold several wallets, one per
 * PLM-CFG-03 catalog `walletRef` (MONEY, VOICE, …); behaviour (unit, applicability,
 * charging precedence, refillability, expiry) comes from the catalog — this service
 * applies those rules to the customer's balance. Currency is NOT catalog behaviour:
 * it is deployment config (operator_config.currency_code), stamped onto the wallet
 * instance at creation — one operator, one currency, no exceptions.
 */
class WalletService
{
    /** Default money wallet code convention when a caller does not name one. */
    public const DEFAULT_WALLET_CODE = 'MONEY';

    public function __construct(
        private readonly EventBus $events,
        private readonly SophixCache $cache,
    ) {}

    /**
     * FOUNDATION_CACHE hot-path read: the per-charge catalog lookup is cache-aside
     * (`sophix:plm:wallet:{operator}:{code}`, 24h TTL; Wallet* catalog events evict via
     * EvictPlmCatalogCache). PostgreSQL stays the source of truth; this module owns it.
     */
    private function catalogEntry(string $operator, string $code): ?WalletType
    {
        $attrs = $this->cache->remember('plm', 'wallet', "{$operator}:{$code}", SophixCache::TTL_CATALOG,
            fn () => WalletType::activeByCode($operator, $code)?->getAttributes());

        return $attrs ? WalletType::hydrate([$attrs])->first() : null;
    }

    /** Cached ACTIVE catalog set for the operator (used by charge-time selection). */
    private function catalogSet(string $operator): Collection
    {
        $rows = $this->cache->remember('plm', 'wallet-types', $operator, SophixCache::TTL_CATALOG,
            fn () => WalletType::query()
                ->where('operator_code', $operator)
                ->where('status', WalletType::STATUS_ACTIVE)
                ->get()->map->getAttributes()->all());

        return WalletType::hydrate($rows ?? [])->keyBy('code');
    }

    /**
     * Resolve (or create) the ledger row for a (subscription, walletRef). The
     * walletRef must resolve to an ACTIVE PLM-CFG-03 catalog entry (R-W-3) — the
     * catalog is the source of truth for what wallets exist; the CURRENCY stamped
     * on a new instance comes from operator_config, not from the catalog entry.
     */
    public function ensureWallet(
        string $subscriptionId,
        string $walletCode = self::DEFAULT_WALLET_CODE,
        ?string $accountId = null,
        ?string $customerId = null,
    ): Wallet {
        $catalog = $this->catalogEntry(Context::operatorCode(), $walletCode);
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
                // One deployment, one currency: stamped from operator config, never from the type.
                'currency' => OperatorConfig::forOperator(Context::operatorCode())?->currency_code
                    ?? config('sophix.default_currency', 'KES'),
                'balance' => 0,
                'status' => Wallet::ACTIVE,
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
        $catalog = $this->catalogSet($operator); // cache-aside (FOUNDATION_CACHE)

        return Wallet::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', Wallet::ACTIVE)
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
        $operator ??= Context::operatorCode();
        $amount = round($amount, 2);
        $wallets = $this->resolveChargingWallets($subscriptionId, 'PREPAID', $operator);
        $catalog = $this->catalogSet($operator);

        // R-W-15: a points wallet's CURRENCY value is balance × points_to_currency_rate;
        // a currency wallet's value is its balance. Settlement works in currency.
        $rateOf = fn (Wallet $w) => $catalog[$w->wallet_code]->isPoints()
            ? (float) $catalog[$w->wallet_code]->points_to_currency_rate : null;
        $valueOf = fn (Wallet $w) => ($r = $rateOf($w)) ? (float) $w->balance * $r : (float) $w->balance;

        $available = (float) $wallets->sum($valueOf);
        if ($available + 0.0001 < $amount) {
            return ['settled' => false, 'debited' => 0.0, 'available' => $available];
        }

        $remaining = $amount;
        foreach ($wallets as $wallet) {
            if ($remaining <= 0.0001) {
                break;
            }
            $rate = $rateOf($wallet);
            $takeCurrency = min($remaining, $valueOf($wallet));
            if ($takeCurrency <= 0) {
                continue;
            }
            // Points wallets are debited in points (currency ÷ rate).
            $this->debit($wallet, $rate ? round($takeCurrency / $rate, 4) : round($takeCurrency, 2), $reason, $reference);
            $remaining -= $takeCurrency;
        }

        return ['settled' => true, 'debited' => $amount, 'available' => $available];
    }

    /**
     * R-W-9 expiry sweep: zero the balance of any expiring wallet past its
     * expires_at. Returns the number of wallets expired.
     */
    public function expireBalances(?string $operator = null): int
    {
        $operator ??= Context::operatorCode();
        $expired = 0;
        Wallet::query()
            ->where('operator_code', $operator)
            ->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->where('balance', '>', 0)
            ->get()
            ->each(function (Wallet $wallet) use (&$expired) {
                $this->post($wallet, WalletTransaction::DEBIT, (float) $wallet->balance, WalletTransaction::REASON_EXPIRY, 'wallet-expiry');
                $wallet->update(['expires_at' => null]);
                $expired++;
            });

        return $expired;
    }

    public function credit(Wallet $wallet, float $amount, string $reason = WalletTransaction::REASON_TOPUP, ?string $reference = null): WalletTransaction
    {
        // R-W-11: a non-refillable wallet rejects top-ups (one-shot promo/bonus credits).
        if ($reason === WalletTransaction::REASON_TOPUP) {
            $catalog = $this->catalogEntry((string) $wallet->operator_code, (string) $wallet->wallet_code);
            if ($catalog && ! $catalog->refillable) {
                throw DomainException::ruleRejected(
                    'WALLET_NOT_REFILLABLE',
                    "Wallet {$wallet->wallet_code} does not accept top-ups.",
                );
            }
            // R-W-9: a top-up to an expiring wallet (re)starts its validity window.
            if ($catalog && $catalog->expires && $catalog->expiry_period_days) {
                $wallet->update(['expires_at' => now()->addDays((int) $catalog->expiry_period_days)]);
            }
        }

        return $this->post($wallet, WalletTransaction::CREDIT, $amount, $reason, $reference);
    }

    public function debit(Wallet $wallet, float $amount, string $reason = WalletTransaction::REASON_CYCLE_CHARGE, ?string $reference = null): WalletTransaction
    {
        if ((float) $wallet->balance < $amount) {
            throw DomainException::ruleRejected(
                'WALLET_INSUFFICIENT_FUNDS',
                'Wallet balance is insufficient for this debit.',
                nextAction: 'TOPUP_WALLET',
            );
        }

        return $this->post($wallet, WalletTransaction::DEBIT, $amount, $reason, $reference);
    }

    private function post(Wallet $wallet, string $direction, float $amount, string $reason, ?string $reference): WalletTransaction
    {
        if ($amount <= 0) {
            throw DomainException::ruleRejected('INVALID_AMOUNT', 'Amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $direction, $amount, $reason, $reference) {
            $wallet = Wallet::query()->whereKey($wallet->wallet_id)->lockForUpdate()->first();
            $newBalance = (float) $wallet->balance + ($direction === WalletTransaction::CREDIT ? $amount : -$amount);
            $wallet->update(['balance' => $newBalance]);

            $txn = $wallet->transactions()->create([
                'direction' => $direction,
                'reason' => $reason,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference' => $reference,
            ]);

            $type = match (true) {
                $reason === WalletTransaction::REASON_TOPUP => BillingEvents::WALLET_TOPPED_UP,
                $direction === WalletTransaction::CREDIT => BillingEvents::WALLET_CREDITED,
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
