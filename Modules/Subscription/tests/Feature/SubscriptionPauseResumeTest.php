<?php

namespace Modules\Subscription\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Models\SubscriptionPauseHistory;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Modules\Workflow\Engine\TaskRegistry;
use Modules\Workflow\Engine\WorkflowEngine;
use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\ProcessInstance;
use Tests\TestCase;

class SubscriptionPauseResumeTest extends TestCase
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

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    private function activeSubscription(): string
    {
        $id = $this->postJson('/api/subscriptions', [
            'customer_id' => 'c1', 'account_id' => 'a1', 'homepass_id' => 'h1', 'package_ref' => 'p1',
        ])->json('subscription_id');
        $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'act'])->assertStatus(202);
        $this->drain();
        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);

        return $id;
    }

    public function test_pause_then_resume_round_trip(): void
    {
        $id = $this->activeSubscription();

        $this->postJson("/api/subscriptions/{$id}/pause", ['reasonCode' => 'SEASONAL'], ['Idempotency-Key' => 'p1'])->assertStatus(202);
        $this->drain();
        $this->assertSame('SUSPENDED', Subscription::find($id)->status_code); // R-PAUSE-S-2: pause = SUSPENDED+reason
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionPaused']);

        $this->postJson("/api/subscriptions/{$id}/resume", [], ['Idempotency-Key' => 'r1'])->assertStatus(202);
        $this->drain();
        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionResumed']);
    }

    public function test_resume_from_non_paused_is_rejected_by_precondition(): void
    {
        $id = $this->activeSubscription(); // ACTIVE, not PAUSED

        $this->postJson("/api/subscriptions/{$id}/resume", [], ['Idempotency-Key' => 'r2'])->assertStatus(202);
        $this->drain();

        // requiredStatus=PAUSED not met -> gateway rejects -> stays ACTIVE.
        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);
    }

    public function test_pause_holds_pending_pause_during_commit_window(): void
    {
        $id = $this->activeSubscription();
        $this->postJson("/api/subscriptions/{$id}/pause", ['reasonCode' => 'CUSTOMER_REQUESTED_PAUSE'], ['Idempotency-Key' => 'pp1'])->assertStatus(202);

        // Step the flow one task at a time so we can observe the transient state
        // that a single --once drain would blow past.
        $instance = ProcessInstance::query()
            ->where('business_key', $id)->where('status', 'RUNNING')->latest('created_at')->firstOrFail();
        $registry = app(TaskRegistry::class);
        $engine = app(WorkflowEngine::class);
        $step = function () use ($instance, $registry, $engine): ?string {
            $task = ExternalTask::query()
                ->where('instance_id', $instance->instance_id)->where('status', 'CREATED')->oldest('created_at')->first();
            if (! $task) {
                return null;
            }
            $result = $registry->resolve($task->topic)->handle(new TaskContext($instance->refresh(), $task));
            $engine->completeExternalTask($task, $result->variables);

            return $task->node_id;
        };

        $step(); // validate (advances through the gateway, parks on enter-pending)
        $step(); // enter-pending -> writes the transient PENDING_PAUSE
        $this->assertSame('PENDING_PAUSE', Subscription::find($id)->status_code);

        $step(); // fulfillment (network)
        $step(); // commit -> SUSPENDED
        $this->assertSame('SUSPENDED', Subscription::find($id)->status_code);
    }

    public function test_pause_history_opens_on_pause_and_closes_on_resume(): void
    {
        $id = $this->activeSubscription();

        $this->postJson("/api/subscriptions/{$id}/pause", ['reasonCode' => 'CUSTOMER_REQUESTED_PAUSE'], ['Idempotency-Key' => 'ph1'])->assertStatus(202);
        $this->drain();
        // An OPEN pause-history row exists (actual_resume_at null).
        $this->assertDatabaseHas('subscription_pause_history', [
            'subscription_id' => $id, 'origin_intent' => 'CUSTOMER_REQUESTED_PAUSE', 'actual_resume_at' => null,
        ]);

        $this->postJson("/api/subscriptions/{$id}/resume", [], ['Idempotency-Key' => 'ph2'])->assertStatus(202);
        $this->drain();
        // The row is now closed.
        $this->assertNull(SubscriptionPauseHistory::open($id));
    }

    public function test_resume_without_open_pause_history_is_rejected(): void
    {
        $id = $this->activeSubscription();
        // Force the master to SUSPENDED with no pause-history row.
        Subscription::where('subscription_id', $id)->update(['status_code' => 'SUSPENDED']);

        $this->postJson("/api/subscriptions/{$id}/resume", [], ['Idempotency-Key' => 'ph3'])->assertStatus(202);
        $this->drain();
        // R-RESUME-OI-2: no open pause row -> gateway rejects -> stays SUSPENDED.
        $this->assertSame('SUSPENDED', Subscription::find($id)->status_code);
    }

    public function test_operation_timeout_sweep_fails_stuck_operation_and_reverts(): void
    {
        $id = $this->activeSubscription();
        // Start the pause but leave it in-flight (do NOT drain). Backdate started_at
        // past the timeout and park the master in the PENDING_PAUSE transient.
        $op = $this->postJson("/api/subscriptions/{$id}/pause", [], ['Idempotency-Key' => 'to1'])->json('operationId');
        SubscriptionOperation::where('operation_id', $op)->update(['started_at' => now()->subSeconds(1000)]);
        Subscription::where('subscription_id', $id)->update(['status_code' => 'PENDING_PAUSE']);

        Artisan::call('sophix:subscription:operation-timeouts');

        $this->assertSame('FAILED', SubscriptionOperation::find($op)->final_state);
        $this->assertSame('OPERATION_TIMEOUT', SubscriptionOperation::find($op)->failure_reason_code);
        // Reverted from the transient back to ACTIVE (prior status).
        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);
    }
}
