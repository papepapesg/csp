<?php

namespace Modules\Workforce\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workforce\Models\ContractorAvailabilitySlot;
use Modules\Workforce\Models\ContractorSlotCommitment;
use Modules\Workforce\Services\ContractorAvailabilityService;
use Tests\TestCase;

/**
 * EM-02 §3.6: a WO finalizing CONSUMES its contractor slot capacity; a cancelled WO
 * RELEASES it. Without this the committed capacity stayed ACTIVE forever (slot leak).
 */
class SlotCommitmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function slot(): ContractorAvailabilitySlot
    {
        return ContractorAvailabilitySlot::query()->create([
            'slot_id' => Id::make('slot'), 'operator_code' => 'WIK', 'contractor_id' => 'con_1', 'tech_region_id' => 'DKR',
            'service_scope' => 'INSTALL', 'day_of_week' => 'ALL_WEEK', 'hour_start' => '08:00', 'hour_end' => '17:00',
            'timezone' => 'Africa/Dakar', 'max_concurrent' => 5, 'emergency_only' => false, 'active' => true,
        ]);
    }

    public function test_finalized_wo_consumes_and_cancelled_wo_releases_the_commitment(): void
    {
        $svc = app(ContractorAvailabilityService::class);
        $slot = $this->slot();
        $when = now()->next('Monday')->setTime(9, 0);

        $svc->commit($slot->slot_id, 'wo_fin', $when);
        $svc->commit($slot->slot_id, 'wo_can', $when);

        // Finalize wo_fin -> its ACTIVE commitment becomes CONSUMED.
        $this->assertSame(1, $svc->consumeForWorkOrder('wo_fin'));
        $this->assertSame(ContractorSlotCommitment::CONSUMED, ContractorSlotCommitment::query()->where('wo_id', 'wo_fin')->value('status'));

        // Cancel wo_can -> RELEASED (capacity restored).
        $this->assertSame(1, $svc->releaseForWorkOrder('wo_can'));
        $this->assertSame(ContractorSlotCommitment::RELEASED, ContractorSlotCommitment::query()->where('wo_id', 'wo_can')->value('status'));

        // Re-running is a no-op (no ACTIVE rows left) — idempotent.
        $this->assertSame(0, $svc->consumeForWorkOrder('wo_fin'));
    }
}
