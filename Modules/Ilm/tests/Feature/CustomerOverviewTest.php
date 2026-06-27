<?php

namespace Modules\Ilm\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Services\CustomerOverviewService;
use Tests\TestCase;

/**
 * Customer 360 read composition: the one-round-trip overview aggregating ILM/SUB/BIL/TCK, and
 * the per-panel resilience — a failing module degrades only its panel, never the whole screen.
 */
class CustomerOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_overview_aggregates_panels_in_one_call(): void
    {
        $cid = Id::make('cust');
        Customer::query()->create(['customer_id' => $cid, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Ada Lovelace', 'primary_msisdn' => '+254712345678']);
        CustomerAccount::query()->create(['account_id' => 'acct_o', 'customer_id' => $cid, 'operator_code' => 'WIK', 'account_number' => 'A-O', 'service_address' => 'x', 'status' => 'ACTIVE']);
        Invoice::query()->create(['invoice_id' => Id::make('inv'), 'account_id' => 'acct_o', 'operator_code' => 'WIK', 'status' => 'OPEN', 'total_amount' => 900, 'amount_due' => 900]);

        $res = $this->getJson("/api/customers/{$cid}/overview")->assertOk();

        $res->assertJsonPath('panels.profile.available', true)
            ->assertJsonPath('panels.profile.data.name', 'Ada Lovelace')
            ->assertJsonPath('panels.accounts.available', true)
            ->assertJsonPath('panels.accounts.data.0.accountNumber', 'A-O')
            ->assertJsonPath('panels.billing.available', true)
            ->assertJsonPath('panels.billing.data.balanceDue', 900)
            ->assertJsonPath('panels.subscriptions.available', true)
            ->assertJsonPath('panels.tickets.available', true)
            ->assertJsonPath('panels.interactions.available', true)
            ->assertJsonPath('panels.notes.available', true);
    }

    public function test_a_failing_panel_degrades_independently(): void
    {
        // The composition mechanism isolates each panel: one throwing resolver does not break
        // the others (CROSS-00 §12 "Customer 360 partial failure").
        $panels = $this->app->make(CustomerOverviewService::class)->compose([
            'profile' => fn () => ['name' => 'Ada'],
            'billing' => fn () => throw new \RuntimeException('billing service down'),
            'tickets' => fn () => [],
        ]);

        $this->assertTrue($panels['profile']['available']);
        $this->assertSame('Ada', $panels['profile']['data']['name']);
        $this->assertFalse($panels['billing']['available']);          // degraded
        $this->assertSame('PANEL_UNAVAILABLE', $panels['billing']['error']);
        $this->assertTrue($panels['tickets']['available']);           // unaffected
    }

    public function test_unknown_customer_overview_404s(): void
    {
        $this->getJson('/api/customers/nope/overview')->assertNotFound();
    }
}
