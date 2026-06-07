<?php

namespace Modules\Ticketing\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ticketing\Database\Seeders\AsrPolicySeeder;
use Modules\Ticketing\Database\Seeders\SlaPolicySeeder;
use Tests\TestCase;

class AsrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SlaPolicySeeder::class);
        $this->seed(AsrPolicySeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_technical_trouble_routes_to_noc_and_raises_work_order(): void
    {
        $res = $this->postJson('/api/asr', [
            'asr_type' => 'TECHNICAL_TROUBLE', 'subject' => 'No internet', 'customer_id' => 'cust_1', 'subscription_id' => 'sub_1',
        ], ['Idempotency-Key' => 'asr-1'])->assertCreated();
        $res->assertJsonPath('asr_type', 'TECHNICAL_TROUBLE')->assertJsonPath('queue', 'NOC')->assertJsonPath('priority', 'HIGH');

        // Auto-created a field work order.
        $this->assertDatabaseHas('work_order', ['type' => 'SUPPORT']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderCreated']);
    }

    public function test_service_request_routes_to_fulfillment(): void
    {
        $this->postJson('/api/asr', ['asr_type' => 'SERVICE_REQUEST', 'subject' => 'Upgrade my plan'], ['Idempotency-Key' => 'asr-2'])
            ->assertCreated()->assertJsonPath('queue', 'FULFILLMENT')->assertJsonPath('category', 'SERVICE_REQUEST');
    }
}
