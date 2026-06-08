<?php

namespace Modules\Subscription\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Osr\Database\Seeders\OsrRmaSeeder;
use Modules\Osr\Models\EquipmentInstance;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

/**
 * SUB-WF cross-module fulfillment triggers: termination raises an OSR-RMA EQP
 * equipment-pickup for each field-active device. (Relocation -> WO-01 SHIFTING is
 * covered in SubscriptionRelocationTest.)
 */
class SubscriptionCrossModuleTriggersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(OsrRmaSeeder::class); // osr-swap flow for the EQP pickup
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
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_1',
            'status_code' => 'ACTIVE', 'currency' => 'KES',
        ]);

        return $sub->subscription_id;
    }

    public function test_terminate_raises_equipment_pickup_for_field_active_devices(): void
    {
        $id = $this->activeSubscription();

        // A serialized device sitting in the field, bound to this subscription.
        $instance = EquipmentInstance::query()->create([
            'instance_id' => Id::make('eqi'), 'operator_code' => 'WIK', 'sku_id' => 'sku_ont',
            'serial' => 'SN-FIELD-1', 'state' => EquipmentInstance::IN_FIELD_ACTIVE, 'subscription_id' => $id,
        ]);

        $this->postJson("/api/subscriptions/{$id}/terminate", ['reasonCode' => 'CUSTOMER_REQUEST'], ['Idempotency-Key' => 'tk1'])
            ->assertStatus(202);
        $this->drain();

        $this->assertSame('TERMINATED', Subscription::find($id)->status_code);
        // An EQP swap-request was raised to recover the field-active device.
        $this->assertDatabaseHas('equipment_swap_request', [
            'kind' => 'EQP', 'subscription_id' => $id, 'source_instance_id' => $instance->instance_id,
        ]);
    }

    public function test_terminate_without_field_equipment_raises_no_pickup(): void
    {
        $id = $this->activeSubscription();

        $this->postJson("/api/subscriptions/{$id}/terminate", ['reasonCode' => 'CUSTOMER_REQUEST'], ['Idempotency-Key' => 'tk2'])
            ->assertStatus(202);
        $this->drain();

        $this->assertSame('TERMINATED', Subscription::find($id)->status_code);
        $this->assertDatabaseMissing('equipment_swap_request', ['subscription_id' => $id]);
    }
}
