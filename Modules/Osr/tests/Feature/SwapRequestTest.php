<?php

namespace Modules\Osr\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Osr\Database\Seeders\OsrRmaSeeder;
use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Models\EquipmentSku;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;
use Modules\Osr\Models\StockLocation;
use Tests\TestCase;

class SwapRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OsrRmaSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    private function contractorVan(string $contractorId): string
    {
        $id = "WIK-VAN-{$contractorId}";
        StockLocation::query()->create([
            'location_id' => $id, 'operator_code' => 'WIK', 'type' => 'CONTRACTOR_VAN',
            'name' => "Van {$contractorId}", 'contractor_id' => $contractorId, 'active' => true,
        ]);

        return $id;
    }

    private function fieldInstance(): EquipmentInstance
    {
        return EquipmentInstance::query()->create([
            'instance_id' => Id::make('eqi'), 'operator_code' => 'WIK', 'sku_id' => 'sku_modem',
            'serial' => 'SN-'.Id::make('x'), 'state' => EquipmentInstance::IN_FIELD_ACTIVE,
            'customer_id' => 'cust_1', 'subscription_id' => 'sub_1',
        ]);
    }

    public function test_hfc_swap_routes_recovered_unit_to_recovering_contractor(): void
    {
        $van = $this->contractorVan('ctr_99');
        $source = $this->fieldInstance();

        $resp = $this->postJson('/api/swap-requests/hfc', [
            'source_instance_id' => $source->instance_id,
            'subscription_id' => 'sub_1', 'customer_id' => 'cust_1',
            'recovery_contractor_id' => 'ctr_99',
        ], ['Idempotency-Key' => 'swap-1'])->assertStatus(202);
        $swapId = $resp->json('entityId');

        $this->drain(); // validate -> slot -> wo -> await (user task parked)
        $this->assertSame('WO_CREATED', EquipmentSwapRequest::find($swapId)->status);

        $this->postJson("/api/swap-requests/{$swapId}/field-visit", ['defect_confirmed' => true])->assertStatus(202);
        $this->drain(); // recover -> provision -> complete

        $swap = EquipmentSwapRequest::find($swapId);
        $this->assertSame('COMPLETED', $swap->status);

        // THE FIX: recovered unit is now in the recovering contractor's van, not main warehouse.
        $source->refresh();
        $this->assertSame(EquipmentInstance::IN_CONTRACTOR_STOCK, $source->state);
        $this->assertSame($van, $source->location_id);
        $this->assertDatabaseHas('stock_movement', ['location_id' => $van, 'reason_code' => 'SWAP_RECOVERY']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'EquipmentSourceRecovered']);
        $this->assertDatabaseHas('vendor_rma_stub', ['swap_id' => $swapId]);
        // INST-7: the recovering contractor is stamped on the instance lifecycle ledger — the
        // field downstream routing reads to send the unit back to that contractor's warehouse.
        $this->assertDatabaseHas('equipment_instance_lifecycle_event', [
            'instance_id' => $source->instance_id, 'to_state' => 'RECOVERED_BY_CONTRACTOR',
            'contractor_id' => 'ctr_99', 'reason_code' => 'CONTRACTOR_RECOVERED_FROM_FIELD',
        ]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'EquipmentInstanceRecoveredByContractor']);
    }

    public function test_eqp_pickup_recovers_equipment_at_termination(): void
    {
        $van = $this->contractorVan('ctr_eqp');
        $source = $this->fieldInstance();

        $resp = $this->postJson('/api/swap-requests/eqp', [
            'source_instance_id' => $source->instance_id, 'subscription_id' => 'sub_1',
            'recovery_contractor_id' => 'ctr_eqp',
        ], ['Idempotency-Key' => 'eqp-1'])->assertStatus(202);
        $swapId = $resp->json('entityId');
        $this->assertSame('EQP', EquipmentSwapRequest::find($swapId)->kind);

        $this->drain();
        $this->postJson("/api/swap-requests/{$swapId}/field-visit", ['recovered' => true])->assertStatus(202);
        $this->drain();

        $swap = EquipmentSwapRequest::find($swapId);
        $this->assertSame('COMPLETED', $swap->status);
        $this->assertSame(EquipmentInstance::IN_CONTRACTOR_STOCK, $source->refresh()->state);
        $this->assertSame($van, $source->location_id);
    }

    public function test_eqr_customer_refuses_return_forfeits_deposit(): void
    {
        $this->contractorVan('ctr_eqr');
        // The unreturned unit's SKU carries the deposit value forfeited on refusal.
        EquipmentSku::query()->create([
            'sku_id' => 'sku_modem', 'operator_code' => 'WIK', 'name' => 'Modem', 'category' => 'ONT',
            'is_serialized' => true, 'deposit_amount' => 3000, 'active' => true,
        ]);
        $source = $this->fieldInstance(); // sku_modem, subscription_id sub_1

        $resp = $this->postJson('/api/swap-requests/eqp', [
            'source_instance_id' => $source->instance_id, 'recovery_contractor_id' => 'ctr_eqr',
        ], ['Idempotency-Key' => 'eqr-1'])->assertStatus(202);
        $swapId = $resp->json('entityId');

        $this->drain();
        // Customer refuses to return the equipment.
        $this->postJson("/api/swap-requests/{$swapId}/field-visit", ['recovered' => false])->assertStatus(202);
        $this->drain();

        $swap = EquipmentSwapRequest::find($swapId);
        $this->assertSame('COMPLETED_WITHOUT_RECOVERY', $swap->status);
        // Equipment never recovered — stays in the field.
        $this->assertSame(EquipmentInstance::IN_FIELD_ACTIVE, $source->refresh()->state);
        // The forfeited deposit is ACTUALLY billed through BIL-01 (was only announced before).
        $this->assertEqualsWithDelta(3000.0, (float) $swap->charge_amount, 0.001);
        $this->assertDatabaseHas('billing_intent', ['subscription_id' => 'sub_1', 'intent_type' => 'DEPOSIT_FORFEITURE', 'amount' => 3000]);
    }

    public function test_equ_upgrade_is_chargeable_and_places_target(): void
    {
        $van = $this->contractorVan('ctr_equ');
        // The swapped device's SKU carries the equipment value charged on an upgrade.
        EquipmentSku::query()->create([
            'sku_id' => 'sku_modem', 'operator_code' => 'WIK', 'name' => 'Modem', 'category' => 'ONT',
            'is_serialized' => true, 'deposit_amount' => 5000, 'active' => true,
        ]);
        $source = $this->fieldInstance();
        $target = EquipmentInstance::query()->create([
            'instance_id' => Id::make('eqi'), 'operator_code' => 'WIK', 'sku_id' => 'sku_modem_v2',
            'serial' => 'SN-'.Id::make('x'), 'state' => EquipmentInstance::IN_CONTRACTOR_STOCK,
        ]);

        $resp = $this->postJson('/api/swap-requests/equ', [
            'source_instance_id' => $source->instance_id, 'target_instance_id' => $target->instance_id,
            'subscription_id' => 'sub_1', 'customer_id' => 'cust_1', 'recovery_contractor_id' => 'ctr_equ',
        ], ['Idempotency-Key' => 'equ-1'])->assertStatus(202);
        $swapId = $resp->json('entityId');

        $this->drain();
        $this->postJson("/api/swap-requests/{$swapId}/field-visit", ['recovered' => true])->assertStatus(202);
        $this->drain();

        $swap = EquipmentSwapRequest::find($swapId);
        $this->assertSame('COMPLETED', $swap->status);
        $this->assertTrue($swap->chargeable);
        $this->assertSame('UPGRADE_FEE', $swap->charge_code);
        // The charge is now ACTUALLY billed (was decided + announced, never charged):
        // charge_amount = the SKU deposit, and a BIL-01 intent is raised.
        $this->assertEqualsWithDelta(5000.0, (float) $swap->charge_amount, 0.001);
        $this->assertDatabaseHas('billing_intent', ['subscription_id' => 'sub_1', 'intent_type' => 'UPGRADE_FEE', 'amount' => 5000]);
        // Target device is now bound to the customer in the field.
        $this->assertSame(EquipmentInstance::IN_FIELD_ACTIVE, $target->refresh()->state);
        $this->assertSame('cust_1', $target->customer_id);
    }

    public function test_ineligible_swap_fails_without_truck_roll(): void
    {
        $this->contractorVan('ctr_1');
        // Source in the warehouse (not in field) -> INVALID_SOURCE_STATE.
        $source = EquipmentInstance::query()->create([
            'instance_id' => Id::make('eqi'), 'operator_code' => 'WIK', 'sku_id' => 'sku_modem',
            'serial' => 'SN-'.Id::make('x'), 'state' => EquipmentInstance::IN_MAIN_WAREHOUSE,
        ]);

        $resp = $this->postJson('/api/swap-requests/hfc', [
            'source_instance_id' => $source->instance_id, 'recovery_contractor_id' => 'ctr_1',
        ], ['Idempotency-Key' => 'swap-2'])->assertStatus(202);
        $swapId = $resp->json('entityId');

        $this->drain();

        $swap = EquipmentSwapRequest::find($swapId);
        $this->assertSame('FAILED', $swap->status);
        $this->assertSame('INVALID_SOURCE_STATE', $swap->failure_code);
        $this->assertNull($swap->work_order_id); // no WO, no truck roll
    }
}
