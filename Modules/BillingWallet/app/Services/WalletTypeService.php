<?php

namespace Modules\Billing\Wallet\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Plm\Models\Service;
use Modules\Billing\Wallet\Models\WalletType;

/**
 * PLM-CFG-03 wallet catalog admin — pure CRUD + DRAFT→ACTIVE→RETIRED lifecycle
 * with the R-PLM-CFG-03-W validation rules enforced at the service boundary
 * (no workflow, no Camunda). Mirrors the Drools rule IDs as error codes.
 */
class WalletTypeService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): WalletType
    {
        $this->validate($data);

        return DB::transaction(function () use ($data) {
            $wallet = WalletType::query()->create($data + ['status' => WalletType::STATUS_DRAFT]);
            $this->emit(CatalogEvents::WALLET_CREATED, $wallet);

            return $wallet;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(WalletType $wallet, array $data): WalletType
    {
        // R-W-2/5/6/7/11: immutable fields cannot change once any Service references it.
        if ($this->isReferenced($wallet)) {
            foreach (['code', 'role', 'unit', 'decimal_precision', 'refillable'] as $immutable) {
                if (array_key_exists($immutable, $data) && (string) $data[$immutable] !== (string) $wallet->{$immutable}) {
                    throw new DomainException('IMMUTABLE_FIELD', "Field {$immutable} is immutable once the wallet is referenced.", 422);
                }
            }
        }
        $this->validate(array_merge($wallet->only(array_keys($wallet->getAttributes())), $data), $wallet);

        return DB::transaction(function () use ($wallet, $data) {
            $wallet->update($data);
            $this->emit(CatalogEvents::WALLET_UPDATED, $wallet);

            return $wallet->refresh();
        });
    }

    /** DRAFT → ACTIVE (R-W-14). Re-validates the create rules defensively. */
    public function activate(WalletType $wallet): WalletType
    {
        if ($wallet->status !== WalletType::STATUS_DRAFT) {
            throw new DomainException('CONFLICT', 'Only a DRAFT wallet can be activated.', 409);
        }
        $this->validate($wallet->getAttributes(), $wallet);

        return DB::transaction(function () use ($wallet) {
            $wallet->update(['status' => WalletType::STATUS_ACTIVE]);
            $this->emit(CatalogEvents::WALLET_ACTIVATED, $wallet);

            return $wallet->refresh();
        });
    }

    /** ACTIVE → RETIRED (R-W-12): blocked while any Service references the wallet. */
    public function retire(WalletType $wallet): WalletType
    {
        if ($wallet->status !== WalletType::STATUS_ACTIVE) {
            throw new DomainException('CONFLICT', 'Only an ACTIVE wallet can be retired.', 409);
        }
        if ($this->isReferenced($wallet)) {
            throw new DomainException('CONFLICT', "Cannot retire wallet {$wallet->code}: it is referenced by a Service.", 409);
        }

        return DB::transaction(function () use ($wallet) {
            $wallet->update(['status' => WalletType::STATUS_RETIRED, 'retired_at' => now()]);
            $this->emit(CatalogEvents::WALLET_RETIRED, $wallet);

            return $wallet->refresh();
        });
    }

    /** True when any Service points its default_wallet_ref at this wallet's code (R-W-12). */
    private function isReferenced(WalletType $wallet): bool
    {
        return Service::query()
            ->where('operator_code', $wallet->operator_code)
            ->where('default_wallet_ref', $wallet->code)
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function validate(array $data, ?WalletType $existing = null): void
    {
        $operator = $data['operator_code'] ?? $existing?->operator_code ?? \App\Foundation\Support\Context::operatorCode();
        $code = $data['code'] ?? $existing?->code;

        // R-W-1: code unique within the operator catalog.
        if ($code !== null) {
            $dupe = WalletType::query()->where('operator_code', $operator)->where('code', $code)
                ->when($existing, fn ($q) => $q->where('wallet_type_id', '!=', $existing->wallet_type_id))
                ->exists();
            if ($dupe) {
                throw new DomainException('R-PLM-CFG-03-W-1', "Wallet code {$code} already exists in the catalog.", 422);
            }
        }

        // R-W-3: unit is the balance semantics — currency, points, or a usage measure.
        // (Currency itself is DEPLOYMENT config, operator_config.currency_code — never here.)
        $unit = $data['unit'] ?? $existing?->unit ?? WalletType::UNIT_CURRENCY;

        // R-W-7: role is what the wallet is FOR — the charging path only drains SETTLEMENT.
        $role = $data['role'] ?? $existing?->role ?? WalletType::ROLE_SETTLEMENT;
        if (! in_array($role, WalletType::ROLES, true)) {
            throw new DomainException('R-PLM-CFG-03-W-7', 'role must be SETTLEMENT, DEPOSIT, or ALLOWANCE.', 422);
        }

        // R-W-6: decimal_precision in [0, 4].
        $precision = (int) ($data['decimal_precision'] ?? $existing?->decimal_precision ?? 2);
        if ($precision < 0 || $precision > 4) {
            throw new DomainException('R-PLM-CFG-03-W-6', 'decimal_precision must be between 0 and 4.', 422);
        }

        // R-W-9: expiry_period_days required (positive) when expires = true.
        $expires = filter_var($data['expires'] ?? $existing?->expires ?? false, FILTER_VALIDATE_BOOL);
        $expiryDays = $data['expiry_period_days'] ?? $existing?->expiry_period_days;
        if ($expires && (! $expiryDays || (int) $expiryDays <= 0)) {
            throw new DomainException('R-PLM-CFG-03-W-9', 'expiry_period_days must be a positive integer when expires=true.', 422);
        }

        // R-W-15: points_to_currency_rate required (>0) for points-unit wallets, null otherwise.
        $rate = $data['points_to_currency_rate'] ?? $existing?->points_to_currency_rate;
        if ($unit === WalletType::UNIT_POINTS) {
            if ($rate === null || (float) $rate <= 0) {
                throw new DomainException('R-PLM-CFG-03-W-15', "points_to_currency_rate (positive) is required when unit='points'.", 422);
            }
        } elseif ($rate !== null) {
            throw new DomainException('R-PLM-CFG-03-W-15', "points_to_currency_rate must be null when unit='currency'.", 422);
        }
    }

    private function emit(string $type, WalletType $wallet): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: CatalogEvents::TOPIC,
            payload: [
                'walletTypeId' => $wallet->wallet_type_id, 'code' => $wallet->code,
                'role' => $wallet->role, 'unit' => $wallet->unit,
                'chargingPrecedence' => $wallet->charging_precedence, 'status' => $wallet->status,
            ],
            aggregateType: 'Wallet',
            aggregateId: $wallet->wallet_type_id,
        ));
    }
}
