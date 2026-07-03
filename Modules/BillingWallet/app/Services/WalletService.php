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
 * PLM-CFG-03 catalog `walletRef` (MONEY, VOICE, …); behaviour (role, unit, charging
 * precedence, refillability, expiry) comes from the catalog — this service applies
 * those rules to the customer's balance. Only SETTLEMENT-role wallets are drained to
 * settle charges. Currency is NOT catalog OR instance data: it is deployment config
 * (operator_config.currency_code), derived on read — one operator, one currency.
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
                'balance' => 0,
                'status' => Wallet::ACTIVE,
            ],
        );
    }

    /**
     * The deployment currency (operator_config.currency_code) — the single currency
     * money wallets transact in. Derived, never stored on the type or the instance.
     */
    public function deploymentCurrency(?string $operator = null): string
    {
        $operator ??= Context::operatorCode();

        return OperatorConfig::forOperator($operator)?->currency_code ?? config('sophix.default_currency', 'KES');
    }

    /**
     * Charge-time wallet selection (PLM-CFG-03 §charging): the subscription's ACTIVE
     * SETTLEMENT wallets (the only role that drains to settle charges — a DEPOSIT is
     * held, an ALLOWANCE is consumed by usage), ordered by `charging_precedence`
     * (lower applied first). This is why "postpaid can't settle from a wallet" needs
     * no flag: a postpaid subscription simply holds no settlement wallet here.
     *
     * @return Collection<int,Wallet>
     */
    public function resolveChargingWallets(string $subscriptionId, ?string $operator = null): Collection
    {
        $operator ??= Context::operatorCode();
        $catalog = $this->catalogSet($operator); // cache-aside (FOUNDATION_CACHE)

        return Wallet::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', Wallet::ACTIVE)
            ->get()
            ->filter(fn (Wallet $w) => $catalog->has($w->wallet_code)
                && $catalog[$w->wallet_code]->isSettlement())
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
        $wallets = $this->resolveChargingWallets($subscriptionId, $operator);
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

    // ── Allowances (in-kind bundles: DATA/SMS/VOICE) ─────────────────────────

    /**
     * Grant an allowance bundle in-kind (e.g. +5000 units of a DATA wallet),
     * (re)starting its expiry window. Not a money top-up — emits WalletCredited,
     * so it never settles a pending money intent.
     */
    public function grantAllowance(string $subscriptionId, string $walletCode, float $units, ?string $accountId = null, ?string $customerId = null, ?string $reference = null): WalletTransaction
    {
        $wallet = $this->ensureWallet($subscriptionId, $walletCode, $accountId, $customerId);
        $catalog = $this->catalogEntry((string) $wallet->operator_code, $walletCode);
        if ($catalog && $catalog->expires && $catalog->expiry_period_days) {
            $wallet->update(['expires_at' => now()->addDays((int) $catalog->expiry_period_days)]);
        }

        return $this->post($wallet, WalletTransaction::CREDIT, $units, WalletTransaction::REASON_ALLOWANCE_GRANT, $reference);
    }

    /**
     * Total remaining allowance units a subscription holds for a CDR usage type
     * (summed across ACTIVE ALLOWANCE wallets whose type covers it). The rating
     * engine deducts this before charging overage.
     */
    public function allowanceBalanceFor(string $subscriptionId, string $usageType, ?string $operator = null): float
    {
        $operator ??= Context::operatorCode();

        return (float) $this->coveringAllowanceWallets($subscriptionId, $usageType, $operator)
            ->sum(fn (Wallet $w) => (float) $w->balance);
    }

    /**
     * Debit up to `units` of a usage type across the subscription's covering ALLOWANCE
     * wallets, draining by charging_precedence. Returns units actually consumed
     * (≤ available) — callers pass the engine's allowanceConsumedUnits, so it fully drains.
     */
    public function consumeAllowance(string $subscriptionId, string $usageType, float $units, ?string $operator = null, ?string $reference = null): float
    {
        $operator ??= Context::operatorCode();
        $remaining = max(0.0, $units);
        if ($remaining <= 0.0) {
            return 0.0;
        }

        $consumed = 0.0;
        foreach ($this->coveringAllowanceWallets($subscriptionId, $usageType, $operator) as $wallet) {
            if ($remaining <= 0.0000001) {
                break;
            }
            $take = min($remaining, (float) $wallet->balance);
            if ($take <= 0) {
                continue;
            }
            $this->post($wallet, WalletTransaction::DEBIT, $take, WalletTransaction::REASON_ALLOWANCE_USE, $reference);
            $consumed += $take;
            $remaining -= $take;
        }

        return round($consumed, 6);
    }

    /** @return Collection<int,Wallet> ACTIVE allowance wallets covering the usage type, by precedence. */
    private function coveringAllowanceWallets(string $subscriptionId, string $usageType, string $operator): Collection
    {
        $catalog = $this->catalogSet($operator);

        return Wallet::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', Wallet::ACTIVE)
            ->where('balance', '>', 0)
            ->get()
            ->filter(fn (Wallet $w) => $catalog->has($w->wallet_code) && $catalog[$w->wallet_code]->coversUsage($usageType))
            ->sortBy(fn (Wallet $w) => $catalog[$w->wallet_code]->charging_precedence)
            ->values();
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
