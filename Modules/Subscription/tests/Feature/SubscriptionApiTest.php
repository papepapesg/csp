<?php

namespace Modules\Subscription\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Subscription\Models\Subscription;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
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

    private function makeSubscription(): string
    {
        return $this->postJson('/api/subscriptions', [
            'customer_id' => 'cust_test',
            'account_id' => 'acct_test',
            'homepass_id' => 'hp_test',
            'package_ref' => 'pkg_test',
        ])->assertCreated()->assertJsonPath('status_code', 'PENDING_ACTIVATION')->json('subscription_id');
    }

    public function test_create_subscription_emits_event(): void
    {
        $id = $this->makeSubscription();
        $this->assertStringStartsWith('sub_', $id);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionCreated']);
    }

    public function test_activate_runs_workflow_and_sets_active(): void
    {
        $id = $this->makeSubscription();

        $response = $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'act-1']);
        $response->assertStatus(202)
            ->assertJsonPath('status', 'ACCEPTED')
            ->assertJsonStructure(['operationId', 'statusUrl', 'correlationId']);

        // Sync queue => workflow ran inline.
        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionActivated']);
        $this->assertDatabaseHas('subscription_operation', ['operation_kind' => 'ACTIVATE', 'current_state' => 'COMPLETED']);
    }

    public function test_activation_is_idempotent_by_key(): void
    {
        $id = $this->makeSubscription();

        $first = $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'dup-key']);
        $second = $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'dup-key']);

        $this->assertSame($first->json('operationId'), $second->json('operationId'));
        $this->assertSame(1, Subscription::find($id)->operations()->count());
    }

    public function test_terminate_then_activate_conflict(): void
    {
        $id = $this->makeSubscription();
        $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'a'])->assertStatus(202);
        $this->postJson("/api/subscriptions/{$id}/terminate", ['reasonCode' => 'CUSTOMER_REQUEST'], ['Idempotency-Key' => 't'])->assertStatus(202);

        $this->assertSame('TERMINATED', Subscription::find($id)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionTerminated']);
    }

    public function test_operation_status_url_is_trackable(): void
    {
        $id = $this->makeSubscription();
        $op = $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'track'])->json('operationId');

        $this->getJson("/api/subscriptions/{$id}/operations/{$op}")
            ->assertOk()
            ->assertJsonPath('current_state', 'COMPLETED')
            ->assertJsonPath('final_state', 'ACTIVE');
    }
}
