<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Services\ProFormaService;
use Modules\Catalog\Models\PackageVersion;
use Modules\Ilm\Models\Customer;
use Modules\Subscription\Models\Subscription;
use Tests\TestCase;

/** BIL-02-GEN-01 Generator 3: pre-cycle pro-forma documents for prepaid subscriptions. */
class ProFormaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function prepaidSub(Carbon $cycleEnd): Subscription
    {
        Customer::query()->firstOrCreate(['customer_id' => 'c1'], [
            'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Jane', 'primary_msisdn' => '+254700000001',
        ]);
        DB::table('package')->insertOrIgnore(['id' => 'pkg_p', 'operator_code' => 'WIK', 'code' => 'P', 'name' => 'P', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $pv = PackageVersion::query()->create(['package_id' => 'pkg_p', 'price' => 1500, 'currency' => 'KES', 'effective_from' => now()->subYear(), 'status' => 'ACTIVE']);

        return Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_p', 'package_version_id' => $pv->getKey(),
            'status_code' => 'ACTIVE', 'currency' => 'KES', 'billing_mode' => 'PREPAID', 'cycle_frequency_months' => 1,
            'current_cycle_start' => now()->subMonth(), 'current_cycle_end' => $cycleEnd,
        ]);
    }

    public function test_scanner_generates_pro_forma_for_a_prepaid_cycle_within_the_window(): void
    {
        $sub = $this->prepaidSub(now()->addDays(3)); // within 5-day window

        $r = app(ProFormaService::class)->scan('WIK');
        $this->assertSame(1, $r['generated']);
        $this->assertDatabaseHas('pro_forma', ['subscription_id' => $sub->subscription_id, 'total_amount' => 1500.00, 'status' => 'ACTIVE']);

        // Idempotent within the cycle: re-run generates nothing new.
        $this->assertSame(0, app(ProFormaService::class)->scan('WIK')['generated']);
    }

    public function test_cycles_outside_the_window_are_not_projected(): void
    {
        $this->prepaidSub(now()->addDays(20)); // too far out
        $this->assertSame(0, app(ProFormaService::class)->scan('WIK')['generated']);
    }

    public function test_regenerating_supersedes_the_prior_pro_forma_and_links_it(): void
    {
        $sub = $this->prepaidSub(now()->addDays(3));
        app(ProFormaService::class)->generate($sub);
        $old = DB::table('pro_forma')->where('subscription_id', $sub->subscription_id)->where('status', 'ACTIVE')->first();

        // Advance the cycle so a fresh pro forma is generated, superseding the old one.
        $sub->update(['current_cycle_end' => now()->addMonth()->addDays(3)]);
        app(ProFormaService::class)->generate($sub->refresh());

        $new = DB::table('pro_forma')->where('subscription_id', $sub->subscription_id)->where('status', 'ACTIVE')->first();
        $superseded = DB::table('pro_forma')->where('pro_forma_id', $old->pro_forma_id)->first();
        $this->assertSame('SUPERSEDED', $superseded->status);
        $this->assertSame($new->pro_forma_id, $superseded->superseded_by); // the chain is recorded
    }
}
