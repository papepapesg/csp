<?php

namespace Modules\Subscription\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Models\Invoice;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Rules\Models\DecisionTable;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
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

    /** Drive the workflow engine to completion (drains external tasks). */
    private function drainWorkflows(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
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

        $response = $this->postJson("/api/subscriptions/{$id}/activate", ['recipient' => '+254712345678'], ['Idempotency-Key' => 'act-1']);
        $response->assertStatus(202)
            ->assertJsonPath('status', 'ACCEPTED')
            ->assertJsonStructure(['operationId', 'statusUrl', 'correlationId']);

        // Async: subscription is still pending until the engine workers run the flow.
        $this->assertSame('PENDING_ACTIVATION', Subscription::find($id)->status_code);

        $this->drainWorkflows();

        // After the sub-activate flow (validate -> gateway -> set active -> notify):
        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionActivated']);
        $this->assertDatabaseHas('subscription_operation', ['operation_kind' => 'ACTIVATE', 'current_state' => 'COMPLETED']);
        // The flow's notify.send step produced a notification (config-driven, no code).
        $this->assertDatabaseHas('notification', ['template_code' => 'SUBSCRIPTION_ACTIVATED']);
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
        $this->drainWorkflows();
        $this->postJson("/api/subscriptions/{$id}/terminate", ['reasonCode' => 'CUSTOMER_REQUEST'], ['Idempotency-Key' => 't'])->assertStatus(202);
        $this->drainWorkflows();

        $this->assertSame('TERMINATED', Subscription::find($id)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionTerminated']);
    }

    public function test_operation_status_url_is_trackable(): void
    {
        $id = $this->makeSubscription();
        $op = $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'track'])->json('operationId');

        // Immediately the operation is RUNNING (tracked via its status URL)...
        $this->getJson("/api/subscriptions/{$id}/operations/{$op}")->assertOk()->assertJsonPath('current_state', 'RUNNING');

        $this->drainWorkflows();

        // ...and COMPLETED once the workflow finishes (ledger reconciled from the engine).
        $this->getJson("/api/subscriptions/{$id}/operations/{$op}")
            ->assertOk()
            ->assertJsonPath('current_state', 'COMPLETED')
            ->assertJsonPath('final_state', 'ACTIVE');
    }

    public function test_activation_is_gated_by_the_eligibility_decision_table(): void
    {
        $id = $this->makeSubscription(); // account_id = acct_test

        // An outstanding balance on the account. The seeded 'activation.eligibility'
        // decision table (default policy) blocks activation when balance > 0 — this
        // is the DROOLS-equivalent rule driving the live flow's 'eligible?' gateway.
        Invoice::query()->create([
            'operator_code' => 'WIK', 'account_id' => 'acct_test', 'currency' => 'KES',
            'status' => 'OPEN', 'issue_date' => now(), 'due_date' => now()->addDays(7),
            'subtotal_amount' => 1500, 'total_amount' => 1500, 'amount_due' => 1500,
        ]);

        $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'gated'])->assertStatus(202);
        $this->drainWorkflows();

        // Gateway took the rejected branch — subscription is NOT activated.
        $this->assertSame('PENDING_ACTIVATION', Subscription::find($id)->status_code);
        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'SubscriptionActivated']);

        // Now an operator deploys a lenient policy for WIK (allow despite balance) —
        // no code change — and a fresh activation succeeds.
        DecisionTable::query()->create([
            'table_id' => Id::make('dt'),
            'rule_set' => 'activation.eligibility', 'version' => 2, 'operator_code' => 'WIK',
            'name' => 'WIK lenient', 'hit_policy' => 'FIRST', 'rules' => [],
            'default_output' => ['eligible' => true], 'status' => 'DEPLOYED',
        ]);

        $this->postJson("/api/subscriptions/{$id}/activate", [], ['Idempotency-Key' => 'gated-2'])->assertStatus(202);
        $this->drainWorkflows();
        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);
    }
}
