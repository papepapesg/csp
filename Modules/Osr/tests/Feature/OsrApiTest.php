<?php

namespace Modules\Osr\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OsrApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('OSR_SUPERVISOR');
        Sanctum::actingAs($user);
    }

    private function seedSkuAndLocation(): void
    {
        $this->postJson('/api/equipment-skus', [
            'sku_id' => 'WIK-ONT-HUAWEI', 'name' => 'Huawei ONT', 'category' => 'ONT', 'is_serialized' => true,
        ])->assertCreated();
        $this->postJson('/api/stock-locations', [
            'location_id' => 'WIK-WAREHOUSE-MAIN', 'name' => 'Main Warehouse', 'type' => 'WAREHOUSE',
        ])->assertCreated();
    }

    public function test_stock_movements_update_balance(): void
    {
        $this->seedSkuAndLocation();

        $this->postJson('/api/stock-movements', [
            'sku_id' => 'WIK-ONT-HUAWEI', 'location_id' => 'WIK-WAREHOUSE-MAIN', 'quantity' => 100, 'reason_code' => 'RECEIPT',
        ], ['Idempotency-Key' => 'm1'])->assertCreated();

        $this->postJson('/api/stock-movements', [
            'sku_id' => 'WIK-ONT-HUAWEI', 'location_id' => 'WIK-WAREHOUSE-MAIN', 'quantity' => -10, 'reason_code' => 'ISSUE',
        ], ['Idempotency-Key' => 'm2'])->assertCreated();

        $this->getJson('/api/stock-balances?location=WIK-WAREHOUSE-MAIN&sku=WIK-ONT-HUAWEI')
            ->assertOk()
            ->assertJsonPath('items.0.quantity', '90.00');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StockMoved']);
    }

    public function test_equipment_instance_lifecycle(): void
    {
        $this->seedSkuAndLocation();

        $id = $this->postJson('/api/equipment-instances', [
            'sku_id' => 'WIK-ONT-HUAWEI', 'serial' => 'SN-ABCD-1234', 'location_id' => 'WIK-WAREHOUSE-MAIN',
        ], ['Idempotency-Key' => 'i1'])->assertCreated()->assertJsonPath('state', 'IN_MAIN_WAREHOUSE')->json('instance_id');

        $this->postJson("/api/equipment-instances/{$id}/transition", ['state' => 'IN_CONTRACTOR_STOCK', 'location_id' => 'WIK-VAN-1'])
            ->assertOk()->assertJsonPath('state', 'IN_CONTRACTOR_STOCK');
        $this->postJson("/api/equipment-instances/{$id}/transition", ['state' => 'IN_FIELD_ACTIVE', 'subscription_id' => 'sub_1'])
            ->assertOk()->assertJsonPath('state', 'IN_FIELD_ACTIVE');

        // Search by serial
        $this->getJson('/api/equipment-instances?serial=SN-ABCD-1234')
            ->assertOk()->assertJsonPath('totalElements', 1);

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'EquipmentInstanceStateChanged']);
        $this->assertDatabaseHas('equipment_instance_lifecycle_event', ['instance_id' => $id, 'to_state' => 'IN_FIELD_ACTIVE']);
    }

    public function test_invalid_instance_transition_rejected(): void
    {
        $this->seedSkuAndLocation();
        $id = $this->postJson('/api/equipment-instances', [
            'sku_id' => 'WIK-ONT-HUAWEI', 'serial' => 'SN-X',
        ], ['Idempotency-Key' => 'i2'])->json('instance_id');

        // warehouse -> field_active is not allowed (must go via contractor stock)
        $this->postJson("/api/equipment-instances/{$id}/transition", ['state' => 'IN_FIELD_ACTIVE'])
            ->assertStatus(409);
    }
}
