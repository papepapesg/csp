<?php

namespace Modules\Osr\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Osr\Models\StockBalance;
use Tests\TestCase;

class ProcurementAuditTest extends TestCase
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

    public function test_purchase_order_receipt_increases_stock(): void
    {
        $po = $this->postJson('/api/purchase-orders', [
            'supplier' => 'Huawei', 'location_id' => 'WH-MAIN',
            'lines' => [['sku_id' => 'sku_ont', 'quantity' => 50, 'unit_cost' => 30]],
        ])->assertCreated()->json('po_id');

        $this->postJson("/api/purchase-orders/{$po}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/purchase-orders/{$po}/receive")->assertOk()->assertJsonPath('status', 'RECEIVED');

        $this->assertEquals(50, StockBalance::query()->where('location_id', 'WH-MAIN')->where('sku_id', 'sku_ont')->value('quantity'));
        $this->assertDatabaseHas('stock_movement', ['location_id' => 'WH-MAIN', 'reason_code' => 'GOODS_RECEIPT']);
    }

    public function test_serialized_receipt_creates_equipment_instances(): void
    {
        $this->postJson('/api/equipment-skus', [
            'sku_id' => 'WIK-ONT-HW', 'name' => 'Huawei ONT', 'category' => 'ONT', 'is_serialized' => true,
        ])->assertCreated();

        $po = $this->postJson('/api/purchase-orders', [
            'supplier' => 'Huawei', 'location_id' => 'WH-MAIN',
            'lines' => [['sku_id' => 'WIK-ONT-HW', 'quantity' => 2, 'unit_cost' => 30]],
        ])->json('po_id');
        $this->postJson("/api/purchase-orders/{$po}/approve");
        $this->postJson("/api/purchase-orders/{$po}/receive", ['serials' => ['WIK-ONT-HW' => ['SN-001', 'SN-002']]])
            ->assertOk()->assertJsonPath('status', 'RECEIVED');

        // R-OSR-02-08: each serialized unit is now an OSR-INSTANCE at the receiving location.
        $this->assertDatabaseHas('equipment_instance', ['serial' => 'SN-001', 'sku_id' => 'WIK-ONT-HW', 'location_id' => 'WH-MAIN', 'state' => 'IN_MAIN_WAREHOUSE']);
        $this->assertDatabaseHas('equipment_instance', ['serial' => 'SN-002', 'sku_id' => 'WIK-ONT-HW']);
    }

    public function test_reconcile_is_idempotent(): void
    {
        $po = $this->postJson('/api/purchase-orders', ['supplier' => 'S', 'location_id' => 'WH-I', 'lines' => [['sku_id' => 'sku_i', 'quantity' => 50]]])->json('po_id');
        $this->postJson("/api/purchase-orders/{$po}/approve");
        $this->postJson("/api/purchase-orders/{$po}/receive");

        $session = $this->postJson('/api/stock-counts', ['location_id' => 'WH-I'])->json('session_id');
        $this->postJson("/api/stock-counts/{$session}/count", ['counts' => [['sku_id' => 'sku_i', 'counted_qty' => 47]]]);
        $this->postJson("/api/stock-counts/{$session}/reconcile")->assertOk();

        // A second reconcile must NOT post the -3 correction again (R-OSR-05-09).
        $this->postJson("/api/stock-counts/{$session}/reconcile")->assertOk();
        $this->assertEquals(47, StockBalance::query()->where('location_id', 'WH-I')->where('sku_id', 'sku_i')->value('quantity'));
        $this->assertSame(1, \Modules\Osr\Models\StockMovement::query()->where('reason_code', 'INVENTORY_AUDIT_ADJUSTMENT')->where('reference', $session)->count());
    }

    public function test_inventory_audit_reconciles_variance(): void
    {
        // Seed 50 in stock.
        $po = $this->postJson('/api/purchase-orders', ['supplier' => 'S', 'location_id' => 'WH-A', 'lines' => [['sku_id' => 'sku_x', 'quantity' => 50]]])->json('po_id');
        $this->postJson("/api/purchase-orders/{$po}/approve");
        $this->postJson("/api/purchase-orders/{$po}/receive");

        $session = $this->postJson('/api/stock-counts', ['location_id' => 'WH-A'])->assertCreated()->json('session_id');
        // Physical count finds only 47 (3 missing).
        $this->postJson("/api/stock-counts/{$session}/count", ['counts' => [['sku_id' => 'sku_x', 'counted_qty' => 47]]])
            ->assertOk()->assertJsonPath('variance_lines', 1);
        $this->postJson("/api/stock-counts/{$session}/reconcile")->assertOk()->assertJsonPath('status', 'RECONCILED');

        // System balance now matches the physical count.
        $this->assertEquals(47, StockBalance::query()->where('location_id', 'WH-A')->where('sku_id', 'sku_x')->value('quantity'));
        $this->assertDatabaseHas('stock_movement', ['location_id' => 'WH-A', 'reason_code' => 'INVENTORY_AUDIT_ADJUSTMENT']);
    }
}
