<?php

namespace Modules\Fulfillment\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Subscription\Models\Subscription;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

/**
 * End-to-end Wave-1 revenue journey: capture -> subscription + install WO ->
 * activate. Exercises real SUB/WO/FUL orchestration + the workflow engine.
 */
class FulfillmentJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function drainWorkflows(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    public function test_capture_creates_subscription_and_install_work_order(): void
    {
        $resp = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'cust_1',
            'account_id' => 'acct_1',
            'homepass_id' => 'hp_1',
            'package_ref' => 'pkg_triple',
            'payment_ref' => 'pay_1',
        ], ['Idempotency-Key' => 'order-1'])->assertStatus(201);

        $order = $resp->json('order');
        $this->assertSame('AWAITING_INSTALL', $order['status']);
        $this->assertStringStartsWith('sub_', $order['subscription_id']);
        $this->assertStringStartsWith('wo_', $order['work_order_id']);

        $this->assertDatabaseHas('subscription', ['subscription_id' => $order['subscription_id'], 'status_code' => 'PENDING_ACTIVATION']);
        $this->assertDatabaseHas('work_order', ['work_order_id' => $order['work_order_id'], 'type' => 'INSTALLATION']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'OrderCaptured']);
    }

    public function test_complete_activates_subscription(): void
    {
        $order = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'cust_2', 'account_id' => 'acct_2', 'homepass_id' => 'hp_2', 'package_ref' => 'pkg_x',
        ], ['Idempotency-Key' => 'order-2'])->json('order');

        $this->postJson("/api/fulfillment-orders/{$order['order_id']}/complete", [], ['Idempotency-Key' => 'complete-2'])
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');

        // FUL-03 started the SUB-WF activation workflow; the engine workers run it.
        $this->drainWorkflows();
        $this->assertSame('ACTIVE', Subscription::find($order['subscription_id'])->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'OrderCompleted']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionActivated']);
    }

    public function test_capture_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'c', 'account_id' => 'a', 'package_ref' => 'p',
        ])->assertForbidden();
    }
}
