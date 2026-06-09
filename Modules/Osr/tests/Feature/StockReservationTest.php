<?php

namespace Modules\Osr\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Osr\Services\StockService;
use Modules\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

/**
 * OSR-01 §1.5 reservation lifecycle: committed WOs reserve stock (available drops),
 * install consumes it as an INSTALL movement (on-hand drops), cancellation releases it.
 */
class StockReservationTest extends TestCase
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

    private function receive(string $sku, string $loc, float $qty): void
    {
        $this->stock()->move(['sku_id' => $sku, 'location_id' => $loc, 'quantity' => $qty, 'reason_code' => 'RECEIPT']);
    }

    public function test_reserve_then_consume_drains_on_hand_via_install_movement(): void
    {
        $this->receive('WIK-CABLE-DROP', 'WIK-VAN-1', 100);

        $this->stock()->reserve('WIK-CABLE-DROP', 'WIK-VAN-1', 30, 'wo_1');
        $a = $this->stock()->availability('WIK-CABLE-DROP', 'WIK-VAN-1');
        $this->assertSame(100.0, $a['onHand']);
        $this->assertSame(30.0, $a['reserved']);
        $this->assertSame(70.0, $a['available']);

        // Install: consume the reservation -> INSTALL movement deducts on-hand.
        $this->assertSame(1, $this->stock()->consumeReservation('wo_1'));
        $a = $this->stock()->availability('WIK-CABLE-DROP', 'WIK-VAN-1');
        $this->assertSame(70.0, $a['onHand']);     // 100 - 30 installed
        $this->assertSame(0.0, $a['reserved']);
        $this->assertSame(70.0, $a['available']);

        $this->assertDatabaseHas('stock_movement', ['sku_id' => 'WIK-CABLE-DROP', 'reason_code' => 'INSTALL', 'reference' => 'wo_1', 'quantity' => -30]);
        $this->assertDatabaseHas('stock_reservation', ['wo_id' => 'wo_1', 'status' => 'CONSUMED']);
    }

    public function test_reserve_rejects_when_insufficient_available(): void
    {
        $this->receive('WIK-ONT', 'WIK-VAN-1', 5);
        $this->stock()->reserve('WIK-ONT', 'WIK-VAN-1', 4, 'wo_a');

        $this->expectExceptionMessage('available'); // only 1 left
        $this->stock()->reserve('WIK-ONT', 'WIK-VAN-1', 2, 'wo_b');
    }

    public function test_release_returns_reserved_to_available(): void
    {
        $this->receive('WIK-SPLITTER', 'WIK-VAN-1', 40);
        $this->stock()->reserve('WIK-SPLITTER', 'WIK-VAN-1', 25, 'wo_c');
        $this->assertSame(15.0, $this->stock()->availability('WIK-SPLITTER', 'WIK-VAN-1')['available']);

        $this->assertSame(1, $this->stock()->releaseReservation('wo_c'));
        $a = $this->stock()->availability('WIK-SPLITTER', 'WIK-VAN-1');
        $this->assertSame(40.0, $a['available']); // back to full, nothing installed
        $this->assertSame(40.0, $a['onHand']);
        $this->assertDatabaseHas('stock_reservation', ['wo_id' => 'wo_c', 'status' => 'RELEASED']);
    }

    public function test_completing_a_work_order_consumes_its_reservations(): void
    {
        $this->receive('WIK-ONT-EG8', 'WIK-VAN-1', 10);

        // A real WO; reserve an ONT against it.
        $wo = app(WorkOrderService::class)->create(['operator_code' => 'WIK', 'type' => 'INSTALLATION', 'kind' => 'INSTALLATION']);
        $this->stock()->reserve('WIK-ONT-EG8', 'WIK-VAN-1', 1, $wo->work_order_id);
        $this->assertSame(9.0, $this->stock()->availability('WIK-ONT-EG8', 'WIK-VAN-1')['available']);

        // Drive the WO to COMPLETED, then dispatch the outbox -> OSR listener consumes.
        $svc = app(WorkOrderService::class);
        $svc->assign($wo, ['contractor_id' => 'con_1']);
        $svc->start($wo->refresh());
        $svc->finalize($wo->refresh(), ['final_reason' => 'INSTALL_COMPLETED']);
        Artisan::call('sophix:outbox:dispatch');

        // On-hand dropped by the installed ONT; reservation consumed.
        $a = $this->stock()->availability('WIK-ONT-EG8', 'WIK-VAN-1');
        $this->assertSame(9.0, $a['onHand']);
        $this->assertSame(0.0, $a['reserved']);
        $this->assertDatabaseHas('stock_reservation', ['wo_id' => $wo->work_order_id, 'status' => 'CONSUMED']);
        $this->assertDatabaseHas('stock_movement', ['sku_id' => 'WIK-ONT-EG8', 'reason_code' => 'INSTALL', 'reference' => $wo->work_order_id]);
    }
}
