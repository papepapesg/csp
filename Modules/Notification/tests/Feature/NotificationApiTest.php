<?php

namespace Modules\Notification\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);
    }

    public function test_send_notification_marks_sent_and_emits_events(): void
    {
        $this->postJson('/api/notifications', [
            'channel' => 'SMS',
            'recipient' => '+254712345678',
            'template_code' => 'PAYMENT_RECEIVED',
            'body' => 'We received your payment.',
            'customer_id' => 'cust_1',
        ], ['Idempotency-Key' => 'n1'])
            ->assertCreated()
            ->assertJsonPath('status', 'SENT');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'NotificationSent']);
    }

    public function test_internal_message_posted(): void
    {
        $this->postJson('/api/internal-messages', [
            'subject' => 'Install escalation',
            'to_group' => 'DISPATCH_NRB',
            'priority' => 'HIGH',
        ])->assertCreated();

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'InternalMessagePosted']);
        $this->getJson('/api/internal-messages?toGroup=DISPATCH_NRB')->assertOk()->assertJsonPath('totalElements', 1);
    }

    public function test_send_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->postJson('/api/notifications', ['channel' => 'SMS', 'recipient' => 'x'])->assertForbidden();
    }
}
