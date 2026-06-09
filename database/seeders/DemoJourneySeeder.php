<?php

namespace Database\Seeders;

use App\Foundation\Support\Context;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;

/**
 * Demo profile sample data: one approved customer with an account and a captured
 * order driven through install + activation, so a fresh demo opens with a living
 * subscription to explore (Customer 360, NOC trace, billing, tickets).
 */
class DemoJourneySeeder extends Seeder
{
    public function run(): void
    {
        Context::setOperatorCode(config('sophix.default_operator', 'WIK'));

        $customer = Customer::query()->updateOrCreate(
            ['primary_msisdn' => '+254712345678'],
            ['operator_code' => Context::operatorCode(), 'type' => 'RES', 'name' => 'Jane Mwangi',
             'email' => 'jane.mwangi@example.com', 'kyc_status' => 'APPROVED'],
        );
        $account = CustomerAccount::query()->updateOrCreate(
            ['account_number' => '002-0598570K'],
            ['operator_code' => Context::operatorCode(), 'customer_id' => $customer->customer_id,
             'service_address' => 'Karen, Nairobi', 'status' => 'ACTIVE', 'sub_status' => 'active'],
        );

        // Capture an order and drive the journey to ACTIVE (install + workers).
        $order = app(OrderCaptureService::class)->capture([
            'customer_id' => $customer->customer_id,
            'account_id' => $account->account_id,
            'homepass_id' => 'hp_demo_1',
            'package_ref' => 'pkg_fiber_100m',
            'created_by' => 'demo-seed',
        ]);
        Artisan::call('sophix:workflow:work', ['--once' => true]);   // … parks awaiting install
        app(OrderCaptureService::class)->complete($order->refresh()); // desk-confirm install
        Artisan::call('sophix:workflow:work', ['--once' => true]);   // KYC gate -> activation
        Artisan::call('sophix:outbox:dispatch');
    }
}
