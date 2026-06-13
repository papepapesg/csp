<?php

namespace Database\Seeders;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;

/**
 * Backoffice demo dataset. Populates every operational surface with living data — several
 * customers driven to DIFFERENT journey stages (so the onboarding funnel/fulfillment monitor
 * show a spread), one restricted subscription, a few tickets and payments — then dispatches the
 * outbox so the reporting mart, dashboard widgets and NOC traces light up. Each block is guarded:
 * a demo seed should populate what it can, never abort midway.
 */
class BackofficeDemoSeeder extends Seeder
{
    public function run(): void
    {
        Context::setOperatorCode(config('sophix.default_operator', 'WIK'));
        $op = Context::operatorCode();

        // Demo stock (locations + balances) so the OSR/inventory surfaces have data.
        $this->safe('stock', fn () => $this->call(\Modules\Osr\Database\Seeders\OsrDemoSeeder::class));

        // Base living customer (reuse the canonical journey seeder).
        $this->safe('base journey', fn () => $this->call(DemoJourneySeeder::class));

        // A spread of customers; capture an order each and advance a varying number of steps so the
        // fulfillment funnel shows orders at several stages.
        $people = [
            ['Amani Otieno', '+254712000002', 'APPROVED', 2],
            ['Brian Kamau', '+254712000003', 'APPROVED', 2],
            ['Cynthia Wairimu', '+254712000004', 'PENDING', 0],
            ['David Mutua', '+254712000005', 'APPROVED', 1],
            ['Esther Njeri', '+254712000006', 'APPROVED', 2],
        ];

        $activeSub = null;
        foreach ($people as $i => [$name, $msisdn, $kyc, $advance]) {
            $this->safe("customer {$name}", function () use ($op, $name, $msisdn, $kyc, $advance, $i, &$activeSub) {
                $customer = Customer::query()->updateOrCreate(
                    ['primary_msisdn' => $msisdn],
                    ['operator_code' => $op, 'type' => 'RES', 'name' => $name, 'email' => strtolower(explode(' ', $name)[0]).'@example.com', 'kyc_status' => $kyc],
                );
                $account = CustomerAccount::query()->updateOrCreate(
                    ['account_number' => '002-DEMO-'.str_pad((string) ($i + 2), 3, '0', STR_PAD_LEFT)],
                    ['operator_code' => $op, 'customer_id' => $customer->customer_id, 'service_address' => 'Nairobi', 'status' => 'ACTIVE', 'sub_status' => 'active'],
                );

                $order = app(OrderCaptureService::class)->capture([
                    'customer_id' => $customer->customer_id, 'account_id' => $account->account_id,
                    'homepass_id' => 'hp_demo_'.($i + 2), 'package_ref' => 'pkg_fiber_100m', 'created_by' => 'demo-seed',
                ]);
                for ($s = 0; $s < $advance; $s++) {
                    Artisan::call('sophix:workflow:work', ['--once' => true]);
                }
                if ($advance >= 2) {
                    $this->safe('complete order', fn () => app(OrderCaptureService::class)->complete($order->refresh()));
                    Artisan::call('sophix:workflow:work', ['--once' => true]);
                    $sub = \Modules\Subscription\Models\Subscription::query()->where('customer_id', $customer->customer_id)->first();
                    $activeSub ??= $sub;
                }
            });
        }

        // One subscription carrying an active partial-service restriction (restriction monitor).
        $this->safe('restriction', function () use ($activeSub) {
            if ($activeSub) {
                $activeSub->update(['active_restrictions' => [['restrictionCode' => 'DATA_THROTTLED_LOW', 'activatedAt' => now()->toIso8601String()]]]);
            }
        });

        // A handful of tickets in varied states. The category must exist in the WIK
        // ticket category catalog (TicketCategorySeeder) so routing/SLA defaults resolve,
        // and each ticket carries a customer_id to satisfy the TCK-2 entity-link rule.
        $this->safe('tickets', function () use ($activeSub) {
            $cust = Customer::query()->where('operator_code', Context::operatorCode())->first();
            if (! $cust) {
                return;
            }
            $svc = app(\Modules\Ticketing\Services\TicketService::class);
            $tickets = [
                ['No internet since morning', 'NO_INTERNET'],
                ['Billing query on last invoice', 'BILLING_DISPUTE'],
                ['Slow speeds in the evening', 'TECHNICAL'],
                ['How do I upgrade my package?', 'GENERAL_INQUIRY'],
            ];
            foreach ($tickets as [$subject, $cat]) {
                $this->safe("ticket {$subject}", fn () => $svc->create([
                    'category' => $cat, 'subject' => $subject,
                    'customer_id' => $cust->customer_id,
                    'subscription_id' => $activeSub?->subscription_id,
                    'opened_by' => 'demo-seed',
                ]));
            }
        });

        // One demo invoice off the active subscription so the billing surfaces (invoices,
        // account ledger) open with data. Uses InvoiceService::generate directly — the full
        // rating/cycle pipeline is out of scope for a demo seed.
        $this->safe('invoice', function () use ($activeSub) {
            if (! $activeSub) {
                return;
            }
            app(\Modules\Billing\Services\InvoiceService::class)->generate(
                [
                    'account_id' => $activeSub->account_id,
                    'customer_id' => $activeSub->customer_id,
                    'subscription_id' => $activeSub->subscription_id,
                    'currency' => $activeSub->currency ?? 'KES',
                ],
                [['description' => 'Fiber Home 100 — monthly subscription', 'quantity' => 1, 'unit_price' => 3000.00]],
            );
        });

        // Project everything into the reporting mart + activity feed.
        $this->safe('dispatch', fn () => Artisan::call('sophix:outbox:dispatch'));
    }

    private function safe(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->command?->warn("  demo seed [{$label}] skipped: ".$e->getMessage());
        }
    }
}
