<?php

namespace Modules\Workforce\Tests\Feature;

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
}
