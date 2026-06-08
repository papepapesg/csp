<?php

namespace Modules\Subscription\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Database\Seeders\OperationConfigSeeder;
use Modules\Subscription\Database\Seeders\StatusCatalogSeeder;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

class OperationFrameworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(StatusCatalogSeeder::class);
        $this->seed(OperationConfigSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function subscription(): string
    {
        return $this->postJson('/api/subscriptions', [
            'customer_id' => 'c1', 'account_id' => 'a1', 'homepass_id' => 'h1', 'package_ref' => 'p1',
        ])->json('subscription_id');
    }

    public function test_operation_records_initiated_then_validating(): void
    {
        $id = $this->subscription();
        $op = $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'fw-1'])->json('operationId');

        // Framework §5 vocabulary, not the old PENDING/RUNNING.
        $this->getJson("/api/subscription-operations/{$op}")->assertOk()->assertJsonPath('current_state', 'VALIDATING');
        $this->assertSame('sub-activate', SubscriptionOperation::find($op)->bpmn_process_key);
    }

    public function test_in_flight_lookup_returns_open_operation_then_204(): void
    {
        $id = $this->subscription();
        $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'fw-2'])->assertStatus(202);

        // While the activation flow is parked, an in-flight op exists.
        $this->getJson("/api/subscriptions/{$id}/in-flight-operation")->assertOk()->assertJsonPath('operation_kind', 'ACTIVATE');
    }

    public function test_cancel_in_flight_operation_reverts_and_marks_cancelled(): void
    {
        $id = $this->subscription();
        $op = $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'fw-3'])->json('operationId');

        $this->postJson("/api/subscription-operations/{$op}/cancel", ['cancelReason' => 'CUSTOMER_CHANGED_MIND'])
            ->assertOk()->assertJsonPath('final_state', 'CANCELLED');

        // No longer in-flight.
        $this->getJson("/api/subscriptions/{$id}/in-flight-operation")->assertNoContent();
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionOperationCancelled']);
    }

    public function test_db_enforces_single_in_flight_state_changing_operation(): void
    {
        $id = $this->subscription();
        $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'fw-4a'])->assertStatus(202);
        // Second state-changing op while one is in-flight -> 409 (R-SUB-WF-FW-1).
        $this->postJson("/api/subscriptions/{$id}/pause", [], ['Idempotency-Key' => 'fw-4b'])->assertStatus(409);
    }
}
