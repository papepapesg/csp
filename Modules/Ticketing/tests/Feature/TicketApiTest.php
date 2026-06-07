<?php

namespace Modules\Ticketing\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ticketing\Models\SlaPolicy;
use Modules\Ticketing\Models\Ticket;
use Tests\TestCase;

class TicketApiTest extends TestCase
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

    private function create(string $priority = 'HIGH'): string
    {
        return $this->postJson('/api/tickets', [
            'category' => 'TECHNICAL', 'priority' => $priority, 'subject' => 'No internet', 'customer_id' => 'cust_1', 'account_id' => 'acct_1',
        ], ['Idempotency-Key' => 'tk-'.$priority])->assertCreated()->assertJsonPath('status', 'OPEN')->json('ticket_id');
    }

    public function test_ticket_lifecycle_with_sla_and_timeline(): void
    {
        $id = $this->create();
        $this->assertStringStartsWith('tck_', $id);

        $this->postJson("/api/tickets/{$id}/assign", ['assignee_id' => 'agent_1'])->assertOk()->assertJsonPath('status', 'ASSIGNED');
        $this->postJson("/api/tickets/{$id}/comments", ['body' => 'Investigating'])->assertCreated();
        $this->postJson("/api/tickets/{$id}/resolve", ['resolution_code' => 'FIXED_REMOTE'])->assertOk()->assertJsonPath('status', 'RESOLVED');
        $this->postJson("/api/tickets/{$id}/close")->assertOk()->assertJsonPath('status', 'CLOSED');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TicketResolved']);
        $this->assertDatabaseHas('ticket_timeline', ['ticket_id' => $id, 'event_type' => 'CLOSED']);
    }

    public function test_technical_ticket_raises_work_order(): void
    {
        $id = $this->create('URGENT');

        $this->postJson("/api/tickets/{$id}/work-orders", ['tech_region_id' => 'KE-NRB-KAREN'])
            ->assertOk()
            ->assertJsonPath('status', 'PENDING_WO');

        $ticket = $this->getJson("/api/tickets/{$id}")->json();
        $this->assertStringStartsWith('wo_', $ticket['work_order_id']);
        $this->assertDatabaseHas('work_order', ['work_order_id' => $ticket['work_order_id'], 'type' => 'SUPPORT', 'source_type' => 'TICKET']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TicketWorkOrderLinked']);
    }

    public function test_create_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->postJson('/api/tickets', ['category' => 'TECHNICAL', 'subject' => 'x'])->assertForbidden();
    }

    public function test_sla_is_data_driven_and_operator_overridable(): void
    {
        // Default catalog: URGENT = 4h.
        $this->postJson('/api/sla-policies', ['priority' => 'URGENT', 'response_hours' => 4])->assertCreated();
        $this->assertSame(4, SlaPolicy::resolveHours('WIK', null, 'URGENT'));

        // Operator override for WIK URGENT -> 1h, with no code change.
        $this->postJson('/api/sla-policies', ['operator_code' => 'WIK', 'priority' => 'URGENT', 'response_hours' => 1])->assertCreated();
        $this->assertSame(1, SlaPolicy::resolveHours('WIK', null, 'URGENT'));
        $this->assertSame(4, SlaPolicy::resolveHours('WTZ', null, 'URGENT')); // other operator unaffected

        // A new URGENT ticket now gets the tighter SLA due date.
        $id = $this->create('URGENT');
        $due = Ticket::find($id)->sla_due_at;
        $this->assertTrue($due->lessThan(now()->addHours(2)));
    }
}
