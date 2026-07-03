<?php

namespace Modules\Billing\Wallet\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Billing\Wallet\Models\WalletType;

/**
 * PLM-CFG-03 default seed — the single wallet_type catalog (currency-neutral;
 * the deployment currency comes from operator_config). Includes the triple-play
 * example from the DD (Broadhub model): an Internet+TV settlement wallet (MONEY)
 * and a separate Voice usage wallet (VOICE) — Services point their
 * default_wallet_ref at one or the other, so a customer holds two operational
 * wallets charged by precedence.
 */
class WalletTypeSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // [code, description, role, unit, precedence, refillable, expires, expiryDays, pointsRate, decimals, coveredUsage]
        $types = [
            ['DATA_BUNDLE', 'Included data allowance (MB)', 'ALLOWANCE', 'DATA', 10, true, true, 30, null, 0, ['DATA']], // burns for DATA usage, monthly
            ['PROMO', 'Promotional credit (one-shot)', 'SETTLEMENT', 'currency', 50, false, true, 90, null, 2, null],    // spent first (expires)
            ['LOYALTY_POINTS', 'Loyalty points', 'SETTLEMENT', 'points', 60, true, true, 365, 0.01, 0, null],            // points → currency at 0.01
            ['BONUS', 'Operator-granted credit', 'SETTLEMENT', 'currency', 80, false, false, null, null, 2, null],
            ['VOICE', 'Phone usage wallet', 'SETTLEMENT', 'currency', 90, true, false, null, null, 2, null],
            ['MONEY', 'Customer top-up balance (Internet+TV settlement)', 'SETTLEMENT', 'currency', 100, true, false, null, null, 2, null],
            ['DEPOSIT', 'Refundable deposit (held, refunded at termination)', 'DEPOSIT', 'currency', 200, true, false, null, null, 2, null],
        ];
        foreach ($types as [$code, $description, $role, $unit, $precedence, $refillable, $expires, $expiryDays, $rate, $decimals, $covered]) {
            WalletType::query()->updateOrCreate(
                ['operator_code' => $operator, 'code' => $code],
                [
                    'wallet_type_id' => Id::make('wtyp'),
                    'description' => $description,
                    'role' => $role,
                    'unit' => $unit,
                    'covered_usage_types' => $covered,
                    'decimal_precision' => $decimals,
                    'charging_precedence' => $precedence,
                    'refillable' => $refillable,
                    'expires' => $expires,
                    'expiry_period_days' => $expiryDays,
                    'points_to_currency_rate' => $rate,
                    'status' => WalletType::STATUS_ACTIVE,
                ],
            );
        }
    }
}
