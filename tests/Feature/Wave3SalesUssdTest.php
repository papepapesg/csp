<?php

namespace Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Models\Invoice;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Tests\TestCase;

class Wave3SalesUssdTest extends TestCase
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

    public function test_franchise_and_lead_conversion(): void
    {
        $this->postJson('/api/franchises', ['code' => 'NRB-01', 'name' => 'Nairobi Central', 'territory' => 'NRB', 'commission_rate' => 0.05])->assertCreated();

        $lead = $this->postJson('/api/leads', [
            'name' => 'New Customer', 'msisdn' => '+254700111222', 'source' => 'FIELD', 'franchise_code' => 'NRB-01', 'assigned_agent' => 'agent_1',
        ], ['Idempotency-Key' => 'lead-1'])->assertCreated()->json('lead_id');

        $this->postJson("/api/leads/{$lead}/qualify")->assertOk()->assertJsonPath('status', 'QUALIFIED');
        $res = $this->postJson("/api/leads/{$lead}/convert")->assertOk()->assertJsonPath('status', 'CONVERTED');
        $this->assertNotNull($res->json('converted_customer_id'));
        $this->assertDatabaseHas('customer', ['customer_id' => $res->json('converted_customer_id'), 'name' => 'New Customer']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SalesLeadConverted']);
    }

    public function test_lead_is_routed_to_the_franchise_covering_its_territory(): void
    {
        $this->postJson('/api/franchises', ['code' => 'KSM-01', 'name' => 'Kisumu', 'territory' => 'KSM', 'commission_rate' => 0.05])->assertCreated();

        // No franchise_code given — the territory routes it to KSM-01.
        $lead = $this->postJson('/api/leads', [
            'name' => 'Territory Lead', 'msisdn' => '+254700333444', 'source' => 'FIELD', 'territory' => 'KSM',
        ], ['Idempotency-Key' => 'lead-terr'])->assertCreated();

        $this->assertSame('KSM-01', $lead->json('franchise_code'));
    }

    public function test_ussd_balance_menu(): void
    {
        $cid = Id::make('cust');
        Customer::query()->create(['customer_id' => $cid, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'U', 'primary_msisdn' => '+254733000111']);
        CustomerAccount::query()->create(['account_id' => 'acct_u', 'customer_id' => $cid, 'operator_code' => 'WIK', 'account_number' => 'A-U', 'service_address' => 'x']);
        Invoice::query()->create(['invoice_id' => Id::make('inv'), 'account_id' => 'acct_u', 'operator_code' => 'WIK', 'status' => 'OPEN', 'total_amount' => 1500, 'amount_due' => 1500]);

        // First dial -> menu.
        $this->post('/api/ussd', ['sessionId' => 's1', 'phoneNumber' => '+254733000111', 'text' => ''])
            ->assertOk()->assertSee('CON Welcome to SOPHIX');
        // Choose 1 -> balance.
        $this->post('/api/ussd', ['sessionId' => 's1', 'phoneNumber' => '+254733000111', 'text' => '1'])
            ->assertOk()->assertSee('END Balance due: 1,500.00 KES');
    }

    public function test_customer_timeline_aggregates_events(): void
    {
        $id = $this->postJson('/api/customers', [
            'type' => 'RES', 'name' => 'Timeline Cust', 'primary_msisdn' => '+254700999000',
        ])->assertCreated()->json('customerId');

        $res = $this->getJson("/api/customers/{$id}/timeline")->assertOk()->assertJsonPath('customerId', $id);
        $this->assertNotEmpty($res->json('items')); // at least CustomerCreated
    }
}
