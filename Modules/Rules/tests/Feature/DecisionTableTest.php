<?php

namespace Modules\Rules\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Tests\TestCase;

class DecisionTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_default_policy_blocks_activation_with_balance(): void
    {
        $this->postJson('/api/rules/activation.eligibility/evaluate', ['facts' => ['outstandingBalance' => 150]])
            ->assertOk()
            ->assertJsonPath('decision.eligible', false)
            ->assertJsonPath('decision.reason', 'OUTSTANDING_BALANCE');

        $this->postJson('/api/rules/activation.eligibility/evaluate', ['facts' => ['outstandingBalance' => 0]])
            ->assertOk()
            ->assertJsonPath('decision.eligible', true);
    }

    public function test_operator_can_override_policy_without_code(): void
    {
        // Tanzania deploys a more lenient policy for the SAME rule set.
        $this->postJson('/api/rules/decision-tables', [
            'rule_set' => 'activation.eligibility',
            'name' => 'Activation eligibility (WTZ)',
            'operator_code' => 'WTZ',
            'hit_policy' => 'FIRST',
            'rules' => [],                       // no blocking rules
            'default_output' => ['eligible' => true],
        ])->assertCreated();

        // Same facts, WIK (default) -> blocked; WTZ (override) -> allowed. No code change.
        $this->postJson('/api/rules/activation.eligibility/evaluate', ['facts' => ['outstandingBalance' => 150]], ['X-Operator-Code' => 'WIK'])
            ->assertOk()->assertJsonPath('decision.eligible', false);

        $this->postJson('/api/rules/activation.eligibility/evaluate', ['facts' => ['outstandingBalance' => 150]], ['X-Operator-Code' => 'WTZ'])
            ->assertOk()->assertJsonPath('decision.eligible', true);
    }

    public function test_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->getJson('/api/rules/decision-tables')->assertForbidden();
    }
}
