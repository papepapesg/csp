<?php

namespace Modules\Ilm\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ilm\Database\Seeders\CvmPolicySeeder;
use Tests\TestCase;

class CvmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(CvmPolicySeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_recovery_offer_resolves_by_trigger_and_can_be_accepted(): void
    {
        $res = $this->postJson('/api/cvm-activities', [
            'customer_id' => 'cust_1', 'type' => 'RECOVERY', 'trigger_reason' => 'NON_PAYMENT',
        ])->assertCreated();
        $res->assertJsonPath('status', 'OFFERED')->assertJsonPath('offer_code', 'PAYMENT_PLAN_30D');
        $id = $res->json('activity_id');

        $this->postJson("/api/cvm-activities/{$id}/decide", ['accept' => true])
            ->assertOk()->assertJsonPath('status', 'ACCEPTED');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CvmOfferAccepted']);
    }

    public function test_winback_offer_default_segment(): void
    {
        $this->postJson('/api/cvm-activities', ['customer_id' => 'cust_2', 'type' => 'WINBACK'])
            ->assertCreated()->assertJsonPath('offer_code', 'WINBACK_50OFF_3M');
    }
}
