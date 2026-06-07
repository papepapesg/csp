<?php

namespace Modules\Subscription\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Database\Seeders\RestrictionCatalogSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\RestrictionService;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

class SubscriptionRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(RestrictionCatalogSeeder::class);
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

        return $id;
    }

    public function test_add_restriction_mutates_array_without_changing_status(): void
    {
        $id = $this->activeSubscription();

        $this->postJson("/api/subscriptions/{$id}/restrictions", [
            'restrictionCode' => 'OUTGOING_VOICE_BARRED',
        ], ['Idempotency-Key' => 'r-add-1'])->assertStatus(202)->assertJsonPath('intent', 'ADD');
        $this->drain();

        $sub = Subscription::find($id);
        $this->assertSame('ACTIVE', $sub->status_code); // R-S-2: status unchanged
        $this->assertSame(['OUTGOING_VOICE_BARRED'], array_column($sub->active_restrictions, 'restrictionCode'));
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionRestrictionAdded']);

        // List endpoint reflects the active restriction.
        $this->getJson("/api/subscriptions/{$id}/restrictions")
            ->assertOk()
            ->assertJsonPath('activeRestrictions.0.restrictionCode', 'OUTGOING_VOICE_BARRED');
    }

    public function test_duplicate_add_is_rejected(): void
    {
        $id = $this->activeSubscription();
        $this->postJson("/api/subscriptions/{$id}/restrictions", ['restrictionCode' => 'OUTGOING_VOICE_BARRED'], ['Idempotency-Key' => 'r-add-2'])->assertStatus(202);
        $this->drain();

        $this->postJson("/api/subscriptions/{$id}/restrictions", ['restrictionCode' => 'OUTGOING_VOICE_BARRED'], ['Idempotency-Key' => 'r-add-3'])
            ->assertStatus(409)
            ->assertJsonPath('errorCode', 'ALREADY_RESTRICTED');
    }

    public function test_unknown_code_is_rejected(): void
    {
        $id = $this->activeSubscription();
        $this->postJson("/api/subscriptions/{$id}/restrictions", ['restrictionCode' => 'BOGUS'], ['Idempotency-Key' => 'r-add-4'])
            ->assertStatus(400)
            ->assertJsonPath('errorCode', 'UNKNOWN_RESTRICTION_CODE');
    }

    public function test_remove_restriction_clears_array(): void
    {
        $id = $this->activeSubscription();
        $this->postJson("/api/subscriptions/{$id}/restrictions", ['restrictionCode' => 'OUTGOING_VOICE_BARRED'], ['Idempotency-Key' => 'r-add-5'])->assertStatus(202);
        $this->drain();

        $this->deleteJson("/api/subscriptions/{$id}/restrictions/OUTGOING_VOICE_BARRED", [], ['Idempotency-Key' => 'r-del-1'])
            ->assertStatus(202)->assertJsonPath('intent', 'REMOVE');
        $this->drain();

        $this->assertSame([], Subscription::find($id)->active_restrictions);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionRestrictionRemoved']);
    }

    public function test_dunning_marked_restriction_blocks_human_removal_but_billing_can_lift(): void
    {
        $id = $this->activeSubscription();
        $sub = Subscription::find($id);

        // System (dunning-driven) ADD marks the restriction.
        app(RestrictionService::class)->add($sub, 'OUTGOING_VOICE_BARRED', [
            'activationTrigger' => RestrictionService::TRIGGER_DUNNING,
            'dunningReference' => 'dunning-a1-L2',
            'actorRole' => 'BILLING_INTERNAL',
            'actorUserId' => 'bil04',
            'idempotencyKey' => 'r-dun-add',
        ]);
        $this->drain();
        $this->assertTrue(Subscription::find($id)->active_restrictions[0]['dunningMarker']);

        // Human actor (default strict config) cannot remove it.
        $this->deleteJson("/api/subscriptions/{$id}/restrictions/OUTGOING_VOICE_BARRED", [], ['Idempotency-Key' => 'r-dun-del-human'])
            ->assertStatus(403)
            ->assertJsonPath('errorCode', 'SYSTEM_MANAGED_RESTRICTION');

        // BILLING_INTERNAL (resume-after-payment) may always lift it (R-DM-4).
        $lifted = app(RestrictionService::class)->removeDunningMarked(Subscription::find($id), 'dunning-a1-L2');
        $this->drain();
        $this->assertSame(['OUTGOING_VOICE_BARRED'], $lifted);
        $this->assertSame([], Subscription::find($id)->active_restrictions);
    }
}
