<?php

namespace Modules\Osr\Tests\Feature;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Models\StockReservation;
use Modules\Osr\Services\StockService;
use Tests\TestCase;

/**
 * OSR-01 stock-chain invariants: no negative balances (R-OSR-SC-8), approver required for
 * write-off/cycle-count reasons (R-OSR-SC-9), two-tier transfer topology (R-OSR-SC-4),
 * and reservation expiry (R-OSR-SC-7).
 */
class StockRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    public function test_a_movement_cannot_drive_a_balance_negative(): void
    {
        $this->stock()->move(['sku_id' => 'WIK-ONT', 'location_id' => 'WIK-VAN-1', 'quantity' => 5, 'reason_code' => 'RECEIPT']);

        $this->expectExceptionMessage('negative');
        $this->stock()->move(['sku_id' => 'WIK-ONT', 'location_id' => 'WIK-VAN-1', 'quantity' => -10, 'reason_code' => 'ISSUE']);
    }

    public function test_write_off_is_gated_by_the_em_cfg_04_approval_engine(): void
    {
        // Operator governs a reason catalog: RECEIPT (no approval) + WRITE_OFF_DAMAGE (approval),
        // and an EM-CFG-04 policy for stock movements (the consistent mechanism).
        DB::table('stock_reason_code')->insert([
            ['operator_code' => 'WIK', 'code' => 'RECEIPT', 'description' => 'Receipt', 'direction' => 'IN', 'requires_approval' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['operator_code' => 'WIK', 'code' => 'WRITE_OFF_DAMAGE', 'description' => 'Damaged', 'direction' => 'OUT', 'requires_approval' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        ApprovalDefinition::defineChain('WIK', 'STOCK_MOVEMENT', null, [
            ['approver_kind' => 'ROLE', 'approver_roles' => []],
        ]);
        $this->stock()->move(['sku_id' => 'WIK-ONT', 'location_id' => 'WIK-VAN-1', 'quantity' => 10, 'reason_code' => 'RECEIPT']);

        // A write-off is HELD as a PENDING ApprovalRequest — the movement is not posted yet.
        $result = $this->stock()->submit(['sku_id' => 'WIK-ONT', 'location_id' => 'WIK-VAN-1', 'quantity' => -2, 'reason_code' => 'WRITE_OFF_DAMAGE']);
        $this->assertInstanceOf(ApprovalRequest::class, $result);
        $this->assertSame(ApprovalRequest::PENDING, $result->status);
        $this->assertDatabaseMissing('stock_movement', ['reason_code' => 'WRITE_OFF_DAMAGE']);

        // Approve → the held movement posts, with the approver recorded on the movement.
        app(ApprovalService::class)->decide($result, true, null, 'damaged in transit');
        $this->stock()->applyApproved($result->refresh());
        $this->assertDatabaseHas('stock_movement', ['reason_code' => 'WRITE_OFF_DAMAGE']);
        $this->assertSame(ApprovalRequest::APPROVED, $result->refresh()->status);
    }

    public function test_transfer_must_be_two_tier_no_van_to_van(): void
    {
        DB::table('stock_location')->insert([
            ['location_id' => 'WIK-CENTRAL', 'operator_code' => 'WIK', 'type' => 'WAREHOUSE', 'name' => 'Central', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['location_id' => 'WIK-VAN-A', 'operator_code' => 'WIK', 'type' => 'CONTRACTOR_VAN', 'name' => 'Van A', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['location_id' => 'WIK-VAN-B', 'operator_code' => 'WIK', 'type' => 'CONTRACTOR_VAN', 'name' => 'Van B', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->stock()->move(['sku_id' => 'WIK-ONT', 'location_id' => 'WIK-CENTRAL', 'quantity' => 50, 'reason_code' => 'RECEIPT']);
        $this->stock()->move(['sku_id' => 'WIK-ONT', 'location_id' => 'WIK-VAN-A', 'quantity' => 10, 'reason_code' => 'RECEIPT']);

        // Warehouse → van is allowed.
        $this->stock()->transfer('WIK-ONT', 'WIK-CENTRAL', 'WIK-VAN-A', 20);
        $this->assertSame(30.0, $this->stock()->availability('WIK-ONT', 'WIK-VAN-A')['onHand']);

        // Van → van is rejected.
        $this->expectExceptionMessage('van-to-van');
        $this->stock()->transfer('WIK-ONT', 'WIK-VAN-A', 'WIK-VAN-B', 5);
    }

    public function test_expired_reservation_is_swept_back_to_available(): void
    {
        $this->stock()->move(['sku_id' => 'WIK-ONT', 'location_id' => 'WIK-VAN-1', 'quantity' => 10, 'reason_code' => 'RECEIPT']);
        $this->stock()->reserve('WIK-ONT', 'WIK-VAN-1', 4, 'wo_exp', null, now()->subMinute());
        $this->assertSame(6.0, $this->stock()->availability('WIK-ONT', 'WIK-VAN-1')['available']);

        $this->assertSame(1, $this->stock()->expireReservations('WIK'));
        $a = $this->stock()->availability('WIK-ONT', 'WIK-VAN-1');
        $this->assertSame(10.0, $a['available']); // reserved freed, nothing installed
        $this->assertSame(0.0, $a['reserved']);
        $this->assertDatabaseHas('stock_reservation', ['wo_id' => 'wo_exp', 'status' => StockReservation::EXPIRED]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StockReservationExpired']);
    }
}
