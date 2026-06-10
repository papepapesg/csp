<?php

namespace Modules\Osr\Tests\Feature;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Database\Seeders\StockReasonCodeSeeder;
use Modules\Osr\Services\StockService;
use Tests\TestCase;

/** OSR-01: stock reason-code catalog, two-tier transfer, WO bill-of-materials. */
class StockReasonAndBomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
        $this->seed(StockReasonCodeSeeder::class);
    }

    public function test_movement_reason_must_be_a_catalog_code(): void
    {
        $svc = app(StockService::class);
        $svc->move(['sku_id' => 'sku_1', 'location_id' => 'loc_a', 'quantity' => 10, 'reason_code' => 'RECEIPT']);
        $this->assertDatabaseHas('stock_balance', ['location_id' => 'loc_a', 'sku_id' => 'sku_1', 'quantity' => 10]);

        $this->expectException(DomainException::class);
        $svc->move(['sku_id' => 'sku_1', 'location_id' => 'loc_a', 'quantity' => 1, 'reason_code' => 'MADE_UP']);
    }

    public function test_two_tier_transfer_moves_stock_between_locations(): void
    {
        $svc = app(StockService::class);
        $svc->move(['sku_id' => 'sku_1', 'location_id' => 'loc_a', 'quantity' => 10, 'reason_code' => 'RECEIPT']);

        $svc->transfer('sku_1', 'loc_a', 'loc_b', 4);
        $this->assertDatabaseHas('stock_balance', ['location_id' => 'loc_a', 'sku_id' => 'sku_1', 'quantity' => 6]);
        $this->assertDatabaseHas('stock_balance', ['location_id' => 'loc_b', 'sku_id' => 'sku_1', 'quantity' => 4]);
        // Both legs share one reference (auditable in-transit trail).
        $this->assertSame(2, DB::table('stock_movement')->whereIn('reason_code', ['TRANSFER_IN', 'TRANSFER_OUT'])->distinct('reference')->count('reference') === 1 ? 2 : 0);
    }

    public function test_bill_of_materials_reserves_each_required_sku(): void
    {
        $svc = app(StockService::class);
        $svc->move(['sku_id' => 'ont', 'location_id' => 'van_1', 'quantity' => 5, 'reason_code' => 'RECEIPT']);
        $svc->move(['sku_id' => 'cable', 'location_id' => 'van_1', 'quantity' => 100, 'reason_code' => 'RECEIPT']);
        DB::table('wo_material_requirement')->insert([
            ['operator_code' => 'WIK', 'job_type_code' => 'FTTH_INSTALL', 'sku_id' => 'ont', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['operator_code' => 'WIK', 'job_type_code' => 'FTTH_INSTALL', 'sku_id' => 'cable', 'quantity' => 30, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $reservations = $svc->reserveForJob('FTTH_INSTALL', 'van_1', 'wo_1');
        $this->assertCount(2, $reservations);
        $this->assertDatabaseHas('stock_balance', ['location_id' => 'van_1', 'sku_id' => 'ont', 'qty_reserved' => 1]);
        $this->assertDatabaseHas('stock_balance', ['location_id' => 'van_1', 'sku_id' => 'cable', 'qty_reserved' => 30]);
    }
}
