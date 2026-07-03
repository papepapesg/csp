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

        // [code, description, unit, applicability, precedence, refillable, expires, expiryDays, pointsRate, decimals]
        $types = [
            ['PROMO', 'Promotional credit (one-shot)', 'currency', 'PREPAID_ONLY', 50, false, true, 90, null, 2],   // spent first (expires)
            ['LOYALTY_POINTS', 'Loyalty points', 'points', 'ANY', 60, true, true, 365, 0.01, 0],                    // points → currency at 0.01
            ['BONUS', 'Operator-granted credit', 'currency', 'PREPAID_ONLY', 80, false, false, null, null, 2],
            ['VOICE', 'Phone usage wallet', 'currency', 'PREPAID_ONLY', 90, true, false, null, null, 2],
            ['MONEY', 'Customer top-up balance (Internet+TV settlement)', 'currency', 'PREPAID_ONLY', 100, true, false, null, null, 2],
            ['DEPOSIT', 'Refundable deposit', 'currency', 'ANY', 200, true, false, null, null, 2],                  // held, both billing modes
        ];
        foreach ($types as [$code, $description, $unit, $applicability, $precedence, $refillable, $expires, $expiryDays, $rate, $decimals]) {
            WalletType::query()->updateOrCreate(
                ['operator_code' => $operator, 'code' => $code],
                [
                    'wallet_type_id' => Id::make('wtyp'),
                    'description' => $description,
                    'unit' => $unit,
                    'decimal_precision' => $decimals,
                    'applicability' => $applicability,
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
