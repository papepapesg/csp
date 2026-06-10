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
        $this->seed(\Modules\Ticketing\Database\Seeders\TicketCategorySeeder::class);
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

    public function test_ticket_gets_gap_free_number_attachments_and_links(): void
    {
        $a = $this->postJson('/api/tickets', ['category' => 'TECHNICAL', 'subject' => 'A', 'customer_id' => 'c1'], ['Idempotency-Key' => 'n1'])->assertCreated()->json();
        $b = $this->postJson('/api/tickets', ['category' => 'TECHNICAL', 'subject' => 'B', 'customer_id' => 'c2'], ['Idempotency-Key' => 'n2'])->assertCreated()->json();

        // Gap-free, human-facing, sequential per operator.
        $this->assertSame('TCK-WIK-'.now()->year.'-000001', $a['ticket_number']);
        $this->assertSame('TCK-WIK-'.now()->year.'-000002', $b['ticket_number']);

        // One-step: post the binary straight to the attachments endpoint — it stores the
        // file in FOUNDATION_FILE_STORAGE and links the returned reference to the ticket.
        \Illuminate\Support\Facades\Storage::fake('local');
        $att = $this->post("/api/tickets/{$a['ticket_id']}/attachments", [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('speedtest.png'),
            'visibility' => 'CUSTOMER_VISIBLE',
        ])->assertCreated()->assertJsonPath('file_name', 'speedtest.png')->assertJsonPath('visibility', 'CUSTOMER_VISIBLE')->json();
        // The binary really landed in the foundation registry (with a storage path).
        $this->assertDatabaseHas('file_object', ['file_id' => $att['file_id'], 'owner_type' => 'TICKET', 'owner_id' => $a['ticket_id']]);
        $this->assertNotNull(\App\Foundation\Files\FileObject::find($att['file_id'])->path);

        // Referencing a non-existent foundation file is rejected (TCK-9).
        $this->postJson("/api/tickets/{$a['ticket_id']}/attachments", ['file_id' => 'file_does_not_exist'])
            ->assertStatus(404)->assertJsonPath('errorCode', 'TICKET_ATTACHMENT_FILE_NOT_FOUND');

        $this->postJson("/api/tickets/{$a['ticket_id']}/links", ['entity_type' => 'SUBSCRIPTION', 'entity_ref' => 'sub_1', 'relation' => 'RELATED'])
            ->assertCreated()->assertJsonPath('entity_ref', 'sub_1');

        $this->assertDatabaseHas('ticket_attachment', ['ticket_id' => $a['ticket_id'], 'file_id' => $att['file_id'], 'visibility' => 'CUSTOMER_VISIBLE']);
        $this->assertDatabaseHas('ticket_link', ['ticket_id' => $a['ticket_id'], 'entity_type' => 'SUBSCRIPTION', 'entity_ref' => 'sub_1']);
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
            ->assertJsonPath('status', 'WAITING_WORK_ORDER');

        $ticket = $this->getJson("/api/tickets/{$id}")->json();
        $this->assertStringStartsWith('wo_', $ticket['work_order_id']);
        $this->assertDatabaseHas('work_order', ['work_order_id' => $ticket['work_order_id'], 'type' => 'SUPPORT', 'source_type' => 'TICKET']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TicketWorkOrderCreated']);
        // TCK-6: the WO creation is auditable through a ticket_link row.
        $this->assertDatabaseHas('ticket_link', ['ticket_id' => $id, 'entity_type' => 'WORK_ORDER', 'entity_ref' => $ticket['work_order_id'], 'relation' => 'CREATED_FROM_TICKET']);
    }

    public function test_wo_creation_is_gated_by_category(): void
    {
        $id = $this->postJson('/api/tickets', ['category' => 'BILLING_DISPUTE', 'subject' => 'overcharged', 'customer_id' => 'c2'], ['Idempotency-Key' => 'bd-1'])
            ->assertCreated()->json('ticket_id');

        $this->postJson("/api/tickets/{$id}/work-orders", ['tech_region_id' => 'KE-NRB'])
            ->assertStatus(409)->assertJsonPath('errorCode', 'TICKET_CATEGORY_WO_NOT_ALLOWED');
    }

    public function test_finalizing_the_work_order_resolves_the_ticket(): void
    {
        $id = $this->create('URGENT');
        $woId = $this->postJson("/api/tickets/{$id}/work-orders", ['tech_region_id' => 'KE-NRB'])->assertOk()->json('work_order_id');

        $svc = app(\Modules\WorkOrder\Services\WorkOrderService::class);
        $wo = \Modules\WorkOrder\Models\WorkOrder::find($woId);
        $svc->assign($wo, ['contractor_id' => 'con_1']);
        $svc->start($wo->refresh());
        $svc->finalize($wo->refresh(), ['final_reason' => 'SERVICE_RESTORED']);
        \Illuminate\Support\Facades\Artisan::call('sophix:outbox:dispatch');

        $this->assertSame('RESOLVED', Ticket::find($id)->status);
        $this->assertDatabaseHas('ticket_timeline', ['ticket_id' => $id, 'event_type' => 'LINKED_WORK_ORDER_FINALIZED']);
    }

    public function test_resolved_ticket_can_be_reopened(): void
    {
        $id = $this->create();
        $this->postJson("/api/tickets/{$id}/resolve", ['resolution_code' => 'FIXED'])->assertOk();
        $this->postJson("/api/tickets/{$id}/reopen", ['reason_code' => 'ISSUE_RECURRED'])
            ->assertOk()->assertJsonPath('status', 'OPEN')->assertJsonPath('reopened_count', 1);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TicketReopened']);
    }

    public function test_ticket_must_link_to_an_entity_tck2(): void
    {
        // No customer/account/subscription and no links, on a non-internal category → 422.
        $this->postJson('/api/tickets', ['category' => 'TECHNICAL', 'subject' => 'orphan'], ['Idempotency-Key' => 'orphan-1'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'TICKET_REQUIRES_LINK');

        // An inline link satisfies TCK-2 even without the column refs.
        $id = $this->postJson('/api/tickets', [
            'category' => 'TECHNICAL', 'subject' => 'linked',
            'links' => [['entity_type' => 'EQUIPMENT_INSTANCE', 'entity_ref' => 'eqp_42', 'relation' => 'REPORTED_DEVICE']],
        ], ['Idempotency-Key' => 'linked-1'])->assertCreated()->json('ticket_id');
        $this->assertDatabaseHas('ticket_link', ['ticket_id' => $id, 'entity_type' => 'EQUIPMENT_INSTANCE', 'entity_ref' => 'eqp_42']);
    }

    public function test_cancelling_the_work_order_sends_ticket_back_for_review(): void
    {
        $id = $this->create('URGENT');
        $woId = $this->postJson("/api/tickets/{$id}/work-orders", ['tech_region_id' => 'KE-NRB'])->assertOk()->json('work_order_id');

        $svc = app(\Modules\WorkOrder\Services\WorkOrderService::class);
        $wo = \Modules\WorkOrder\Models\WorkOrder::find($woId);
        $svc->cancel($wo, 'TECH_UNAVAILABLE');
        \Illuminate\Support\Facades\Artisan::call('sophix:outbox:dispatch');

        $ticket = Ticket::find($id);
        // Not assigned to a user, so it parks in WAITING_INTERNAL with the review flag set.
        $this->assertSame('WAITING_INTERNAL', $ticket->status);
        $this->assertTrue((bool) $ticket->requires_review);
        $this->assertNull($ticket->work_order_id);
        $this->assertDatabaseHas('ticket_timeline', ['ticket_id' => $id, 'event_type' => 'LINKED_WORK_ORDER_CANCELLED']);
    }

    public function test_first_response_is_stamped_on_first_agent_action(): void
    {
        $id = $this->create();
        $this->assertNull(Ticket::find($id)->first_response_at);
        $this->assertNotNull(Ticket::find($id)->first_response_due_at); // SLA clock started at create

        $this->postJson("/api/tickets/{$id}/assign", ['assignee_id' => 'agent_1'])->assertOk();
        $first = Ticket::find($id)->first_response_at;
        $this->assertNotNull($first);

        // A later comment does not move the (already stamped) first response.
        $this->postJson("/api/tickets/{$id}/comments", ['body' => 'follow-up'])->assertCreated();
        $this->assertEquals($first->timestamp, Ticket::find($id)->first_response_at->timestamp);
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
