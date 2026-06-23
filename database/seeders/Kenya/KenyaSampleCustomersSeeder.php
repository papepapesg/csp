<?php

namespace Database\Seeders\Kenya;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Models\CustomerAccountFlag;
use Modules\Subscription\Models\Subscription;

/**
 * A small live operational sample for the Kenya deployment, so the deployed BSS
 * opens with real CAS data exercising the platform's configured behaviour:
 *
 *   - Customer → Account → Subscription (the 3-tier CAS), account_number as the
 *     sole operational key;
 *   - service class (VIP / Platinum / Gold — informational, no billing impact);
 *   - ANNIVERSARY cycles on a 1–28 anchor day with a 30-day proration basis;
 *   - a triple-play subscriber (dual-wallet billing) and an NPD-flagged account
 *     (independent of lifecycle status, the CVM recovery population).
 *
 * Seeds master rows directly in their settled state (no workflow side effects),
 * which is appropriate for deployment seed data. Idempotent.
 */
class KenyaSampleCustomersSeeder extends Seeder
{
    private const OP = 'WIK';

    public function run(): void
    {
        Context::setOperatorCode(self::OP);

        // [key, name, type, msisdn, idType, idNo, serviceClass, homepass, package, version, anchorDay, npd]
        $rows = [
            ['ke_cust_001', 'Achieng Otieno', 'RES', '+254712000001', 'NATIONAL_ID', '21000001', 'VIP',
                'hp_ke_0001', 'pkg_zuku_triple_100', 'pkv_pkg_zuku_triple_100', 5, false],
            ['ke_cust_002', 'Brian Mwangi', 'RES', '+254712000002', 'NATIONAL_ID', '21000002', 'Gold',
                'hp_ke_0003', 'pkg_zuku_fibre_100', 'pkv_pkg_zuku_fibre_100', 12, true],
            ['ke_cust_003', 'Coastline Traders Ltd', 'COM', '+254712000003', 'BUSINESS_REG', 'CPR-330021', 'Platinum',
                'hp_ke_0008', 'pkg_zuku_double_100', 'pkv_pkg_zuku_double_100', 20, false],
        ];

        $seq = 1_000_000;
        foreach ($rows as [$key, $name, $type, $msisdn, $idType, $idNo, $serviceClass, $hp, $pkg, $pkv, $anchor, $npd]) {
            $seq++;
            $customerId = 'cust_'.$key;
            $accountId = 'acct_'.$key;
            $subscriptionId = 'sub_'.$key;
            $accountNumber = '002-'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT).'T';

            Customer::query()->updateOrCreate(
                ['customer_id' => $customerId],
                ['operator_code' => self::OP, 'type' => $type, 'name' => $name,
                    'identification_type_1' => $idType, 'identification_number_1' => $idNo,
                    'primary_msisdn' => $msisdn, 'email' => null, 'preferred_language' => 'en',
                    'kyc_status' => 'APPROVED'],
            );

            $cycleStart = now()->startOfDay()->subDays(10);
            CustomerAccount::query()->updateOrCreate(
                ['account_id' => $accountId],
                ['account_number' => $accountNumber, 'payment_account_number' => $accountNumber,
                    'customer_id' => $customerId, 'operator_code' => self::OP, 'homepass_id' => $hp,
                    'service_address' => 'See Home Pass '.$hp, 'status' => CustomerAccount::STATUS_ACTIVE,
                    'sub_status' => 'active', 'service_class_1' => $serviceClass, 'subscription_id' => $subscriptionId,
                    'start_bill_date' => $cycleStart->toDateString(), 'install_date' => $cycleStart->toDateString()],
            );

            Subscription::query()->updateOrCreate(
                ['subscription_id' => $subscriptionId],
                ['customer_id' => $customerId, 'account_id' => $accountId, 'operator_code' => self::OP,
                    'homepass_id' => $hp, 'package_ref' => $pkg, 'package_version_id' => $pkv,
                    'status_code' => 'ACTIVE', 'billing_mode' => 'POSTPAID', 'currency' => 'KES',
                    'cycle_model' => 'ANNIVERSARY', 'cycle_anchor_day' => $anchor, 'cycle_period_days' => 30,
                    'cycle_frequency_months' => 1, 'current_cycle_start' => $cycleStart,
                    'current_cycle_end' => $cycleStart->copy()->addDays(30), 'activated_at' => $cycleStart,
                    'start_date' => $cycleStart->toDateString(), 'last_status_changed_at' => $cycleStart,
                    'created_by' => 'seed'],
            );

            if ($npd) {
                CustomerAccountFlag::query()->updateOrCreate(
                    ['account_id' => $accountId, 'flag_code' => 'NPD'],
                    ['id' => Id::make('caf'), 'operator_code' => self::OP, 'bool_value' => true,
                        'state' => 'ACTIVE', 'source' => 'MANUAL', 'set_by' => 'seed', 'set_at' => now()],
                );
            }
        }
    }
}
