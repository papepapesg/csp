<?php

namespace Modules\Workforce\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkforceApiTest extends TestCase
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

    public function test_contractor_team_and_staff_registry(): void
    {
        $con = $this->postJson('/api/contractors', [
            'code' => 'CON-NRB-A', 'name' => 'Nairobi Contractor A', 'type' => 'EXTERNAL', 'skills' => ['GPON', 'HFC'],
        ])->assertCreated()->json('contractor_id');
        $this->assertStringStartsWith('con_', $con);

        $this->postJson("/api/contractors/{$con}/teams", ['code' => 'T1', 'name' => 'Team 1', 'skills' => ['GPON']])
            ->assertCreated();

        $this->postJson('/api/staff', [
            'name' => 'John Tech', 'role' => 'TECHNICIAN', 'contractor_id' => $con, 'skills' => ['GPON'],
        ])->assertCreated()->assertJsonPath('role', 'TECHNICIAN');

        $this->getJson("/api/staff?contractorId={$con}")->assertOk()->assertJsonPath('totalElements', 1);
    }

    public function test_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->postJson('/api/contractors', ['code' => 'x', 'name' => 'y'])->assertForbidden();
    }

    public function test_contractor_availability_and_slot_capacity(): void
    {
        $con = $this->postJson('/api/contractors', ['code' => 'CON-A', 'name' => 'Franchise A', 'type' => 'EXTERNAL'])->json('contractor_id');
        $region = 'KE-NRB-KAREN';

        // Config: this contractor covers INSTALL in the region as PRIMARY, is fiber-install certified,
        // and runs a Monday 09:00–18:00 slot with a concurrency cap of 2.
        \Modules\Workforce\Models\ContractorRegionScope::query()->create([
            'coverage_id' => Id::make('cov'), 'operator_code' => 'WIK', 'contractor_id' => $con,
            'tech_region_id' => $region, 'service_scope' => 'INSTALL', 'coverage_role' => 'PRIMARY', 'effective_from' => now()->subYear()->toDateString(),
        ]);
        \Modules\Workforce\Models\ContractorRegionSkill::query()->create([
            'contractor_id' => $con, 'tech_region_id' => $region, 'operator_code' => 'WIK', 'skill_code' => 'fiber-install', 'active' => true,
        ]);
        $slot = \Modules\Workforce\Models\ContractorAvailabilitySlot::query()->create([
            'slot_id' => Id::make('slot'), 'operator_code' => 'WIK', 'contractor_id' => $con, 'tech_region_id' => $region,
            'service_scope' => 'INSTALL', 'day_of_week' => 'MONDAY', 'hour_start' => '09:00:00', 'hour_end' => '18:00:00',
            'timezone' => 'Africa/Nairobi', 'max_concurrent' => 2, 'emergency_only' => false, 'active' => true,
        ]);

        $monday = \Illuminate\Support\Carbon::parse('next monday', 'Africa/Nairobi')->setTime(14, 0);

        // Hot path: ranked contractors with capacity for the window.
        $this->postJson('/api/contractor-availability', [
            'techRegionId' => $region, 'serviceScope' => 'INSTALL', 'requiredSkills' => ['fiber-install'],
            'windowStart' => $monday->toIso8601String(), 'windowEnd' => $monday->copy()->addHours(2)->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('totalAvailable', 1)
            ->assertJsonPath('availableContractors.0.contractorId', $con)
            ->assertJsonPath('availableContractors.0.rank', 1)
            ->assertJsonPath('availableContractors.0.slot.remainingCapacity', 2);

        // A contractor lacking the required skill is excluded (hard filter R-EM-CS-3).
        $this->postJson('/api/contractor-availability', [
            'techRegionId' => $region, 'serviceScope' => 'INSTALL', 'requiredSkills' => ['vip-handling'],
            'windowStart' => $monday->toIso8601String(), 'windowEnd' => $monday->copy()->addHours(2)->toIso8601String(),
        ])->assertOk()->assertJsonPath('totalAvailable', 0);

        // Commit two WOs (cap 2) — both succeed; the third is rejected (R-EM-CS-6).
        $this->postJson('/api/contractor-slot-commitments', ['slotId' => $slot->slot_id, 'woId' => 'wo_1', 'committedForDatetime' => $monday->toIso8601String()])->assertCreated();
        $cmt2 = $this->postJson('/api/contractor-slot-commitments', ['slotId' => $slot->slot_id, 'woId' => 'wo_2', 'committedForDatetime' => $monday->toIso8601String()])->assertCreated()->json('commitment_id');
        $this->postJson('/api/contractor-slot-commitments', ['slotId' => $slot->slot_id, 'woId' => 'wo_3', 'committedForDatetime' => $monday->toIso8601String()])
            ->assertStatus(409)->assertJsonPath('errorCode', 'INSUFFICIENT_CAPACITY');

        // Releasing one commitment restores capacity (R-EM-CS-7).
        $this->deleteJson("/api/contractor-slot-commitments/{$cmt2}")->assertOk();
        $this->postJson('/api/contractor-slot-commitments', ['slotId' => $slot->slot_id, 'woId' => 'wo_3', 'committedForDatetime' => $monday->toIso8601String()])->assertCreated();
    }
}
