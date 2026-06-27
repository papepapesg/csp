<?php

namespace Modules\Catalog\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Catalog\Wallet\Models\WalletCatalog;
use Modules\Catalog\Wallet\Models\WalletType;

/**
 * PLM-CFG-03 default seed: the 7 monetary wallet types and a starter set of wallet
 * catalog entries. Includes the triple-play example from the DD (Broadhub model):
 * an Internet+TV settlement wallet (MONEY_KES) and a separate Voice usage wallet
 * (VOICE_KES) — Services point their default_wallet_ref at one or the other, so a
 * customer holds two operational wallets charged by precedence.
 */
class WalletCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // wallet_type sub-catalog (code, unit). All monetary except loyalty points.
        $types = [
            ['MONEY', 'Customer top-up balance', 'currency'],
            ['BONUS', 'Operator-granted credit', 'currency'],
            ['PROMO', 'Promotional credit', 'currency'],
            ['DEPOSIT', 'Refundable deposit', 'currency'],
            ['LOYALTY_POINTS', 'Loyalty points', 'points'],
            ['SERVICE_CREDIT', 'SLA-driven compensation', 'currency'],
            ['BUYBACK_CREDIT', 'Hardware-buyback credit', 'currency'],
        ];
        foreach ($types as [$code, $name, $unit]) {
            WalletType::query()->updateOrCreate(
                ['operator_code' => $operator, 'code' => $code],
                ['wallet_type_id' => Id::make('wtyp'), 'name' => $name, 'unit' => $unit, 'currency' => 'KES'],
            );
        }

        // wallet catalog entries: [code, type, applicability, precedence, refillable, expires, expiryDays, pointsRate, decimals]
        $wallets = [
            ['PROMO_KES', 'PROMO', 'PREPAID_ONLY', 50, false, true, 90, null, 2],            // spent first (expires)
            ['LOYALTY_POINTS_KES', 'LOYALTY_POINTS', 'ANY', 60, true, true, 365, 0.01, 0],   // points → KES at 0.01
            ['BONUS_KES', 'BONUS', 'PREPAID_ONLY', 80, false, false, null, null, 2],
            ['VOICE_KES', 'MONEY', 'PREPAID_ONLY', 90, true, false, null, null, 2],          // Phone usage wallet
            ['MONEY_KES', 'MONEY', 'PREPAID_ONLY', 100, true, false, null, null, 2],         // Internet+TV settlement
            ['DEPOSIT_KES', 'DEPOSIT', 'ANY', 200, true, false, null, null, 2],              // held, both billing modes
        ];
        foreach ($wallets as [$code, $type, $applicability, $precedence, $refillable, $expires, $expiryDays, $rate, $decimals]) {
            WalletCatalog::query()->updateOrCreate(
                ['operator_code' => $operator, 'code' => $code],
                [
                    'wallet_catalog_id' => Id::make('wcat'),
                    'description' => $code,
                    'wallet_type_code' => $type,
                    'currency' => 'KES',
                    'decimal_precision' => $decimals,
                    'applicability' => $applicability,
                    'charging_precedence' => $precedence,
                    'refillable' => $refillable,
                    'expires' => $expires,
                    'expiry_period_days' => $expiryDays,
                    'points_to_currency_rate' => $rate,
                    'status' => WalletCatalog::STATUS_ACTIVE,
                ],
            );
        }
    }
}
