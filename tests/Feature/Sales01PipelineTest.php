<?php

namespace Tests\Feature;

use App\Models\SalesLead;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ilm\Models\Customer;
use Tests\TestCase;

/**
 * SALES-01: lead capture (lead number, territory routing, duplicate detection), assignment,
 * activity-driven progression, qualification, and lead→FUL-02 conversion with an immutable
 * attribution event.
 */
class Sales01PipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(\Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder::class);
        $this->seed(\Modules\Fulfillment\Database\Seeders\FulfillmentFlowSeeder::class);
        $this->seed(\Modules\Rules\Database\Seeders\DecisionTableSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);

        $this->postJson('/api/sales/territories', ['territory_code' => 'SALES-KE-NRB-LAV', 'name' => 'Lavington', 'franchise_contractor_id' => 'fr-NRB-A'])->assertCreated();
    }

    private function createLead(array $over = []): string
    {
        return $this->postJson('/api/sales/leads', array_merge([
            'sourceChannel' => 'DOOR_TO_DOOR', 'prospectName' => 'Jane Njeri', 'primaryPhone' => '+254712345678',
            'territoryCode' => 'SALES-KE-NRB-LAV', 'consentCaptured' => true, 'createdByAgentId' => 'agt-04',
            'packageInterest' => [['packageId' => 'pkg-50M', 'priority' => 1]],
        ], $over), ['Idempotency-Key' => $over['_idem'] ?? uniqid()])->assertCreated()->json('lead_id');
    }

    public function test_lead_capture_routes_territory_and_assigns_number(): void
    {
        $id = $this->createLead();
        $lead = SalesLead::find($id);

        $this->assertStringStartsWith('SL-WIK-', $lead->lead_number);
        $this->assertSame('fr-NRB-A', $lead->franchise_code);             // routed from territory
        $this->assertSame('ASSIGNED', $lead->status);                     // auto-assigned to the agent
        $this->assertDatabaseHas('sales_lead_assignment', ['lead_id' => $id, 'assigned_agent_id' => 'agt-04', 'active' => true]);
        $this->assertDatabaseHas('sales_lead_package_interest', ['lead_id' => $id, 'package_id' => 'pkg-50M']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SalesLeadCreated']);
    }

    public function test_duplicate_existing_customer_is_flagged(): void
    {
        Customer::query()->create(['customer_id' => 'cust_dup', 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Dup', 'primary_msisdn' => '+254700000001']);

        $id = $this->createLead(['primaryPhone' => '+254700000001']);
        $this->assertSame('POSSIBLE_CUSTOMER', SalesLead::find($id)->duplicate_risk);
    }

    public function test_activity_progresses_lead_then_qualify(): void
    {
        $id = $this->createLead();

        $this->postJson("/api/sales/leads/{$id}/activities", ['activityType' => 'VISIT', 'outcomeCode' => 'INTERESTED', 'agentId' => 'agt-04'])
            ->assertCreated();
        $this->assertSame('CONTACTED', SalesLead::find($id)->status);

        $this->postJson("/api/sales/leads/{$id}/qualify")->assertOk()->assertJsonPath('status', 'QUALIFIED');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SalesLeadQualified']);
    }

    public function test_convert_to_order_creates_ful_order_and_attribution(): void
    {
        $id = $this->createLead();
        $this->postJson("/api/sales/leads/{$id}/qualify")->assertOk();

        $this->postJson("/api/sales/leads/{$id}/convert-to-order", [
            'selectedPackageId' => 'pkg-50M',
            'customerDraft' => ['fullName' => 'Jane Njeri', 'primaryPhone' => '+254712345678'],
            'salesAttribution' => ['agentId' => 'agt-04', 'teamId' => 'team-04', 'franchiseContractorId' => 'fr-NRB-A', 'territoryCode' => 'SALES-KE-NRB-LAV'],
        ], ['Idempotency-Key' => 'conv-1'])->assertCreated();

        $lead = SalesLead::find($id);
        $this->assertSame('CONVERTED', $lead->status);
        $this->assertDatabaseHas('sales_conversion', ['lead_id' => $id, 'customer_id' => $lead->converted_customer_id]);
        // FUL-02 owns the order; SALES stores the reference.
        $this->assertDatabaseHas('fulfillment_order', ['customer_id' => $lead->converted_customer_id, 'package_ref' => 'pkg-50M']);
        // SALES-5: immutable attribution event for commission.
        $this->assertDatabaseHas('sales_attribution_event', ['lead_id' => $id, 'agent_id' => 'agt-04', 'event_type' => 'LEAD_CONVERTED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SalesLeadConverted']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SalesAttributionRecorded']);
    }

    public function test_converted_lead_cannot_be_reassigned(): void
    {
        $id = $this->createLead();
        $this->postJson("/api/sales/leads/{$id}/qualify")->assertOk();
        $this->postJson("/api/sales/leads/{$id}/convert-to-order", ['selectedPackageId' => 'pkg-50M', 'customerDraft' => ['fullName' => 'J', 'primaryPhone' => '+254712345678']], ['Idempotency-Key' => 'conv-2'])->assertCreated();

        $this->postJson("/api/sales/leads/{$id}/assign", ['assignedAgentId' => 'agt-99'])->assertStatus(409); // SALES-6
    }

    public function test_agent_daily_work_lists_active_leads(): void
    {
        $this->createLead();
        $this->getJson('/api/sales/agents/agt-04/daily-work')->assertOk()
            ->assertJsonPath('leads.0.assigned_agent', 'agt-04');
    }
}
