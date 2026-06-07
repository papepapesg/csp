<?php

namespace Modules\Subscription\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
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
        $this->assertSame('PAUSED', Subscription::find($id)->status_code);
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
}
