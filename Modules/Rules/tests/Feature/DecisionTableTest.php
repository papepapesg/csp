<?php

namespace Modules\Rules\Tests\Feature;

use App\Foundation\Rules\RuleEngine;
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
        $this->seed(DecisionTableSeeder::class); // seeds rules.subscription.activate
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_rule_package_returns_decision_with_ruleid_per_foundation_drools(): void
    {
        // Blocking precondition fires -> eligible false + a ValidationError carrying ruleId.
        $assess = app(RuleEngine::class)->assess('rules.subscription.activate', ['outstandingBalance' => 1500]);
        $this->assertFalse($assess['decision']['eligible']);
        $this->assertSame('R-SUB-ACT-001', $assess['decision']['ruleId']);
        $this->assertSame('PAY_FIRST_REQUIRED', $assess['decision']['decisionCode']);
        $this->assertSame('R-SUB-ACT-001', $assess['validationErrors'][0]['ruleId']);

        // No rule fires (empty results = pass).
        $clean = app(RuleEngine::class)->assess('rules.subscription.activate', ['outstandingBalance' => 0]);
        $this->assertTrue($clean['decision']['eligible']);
        $this->assertSame([], $clean['validationErrors']);
    }

    public function test_evaluate_endpoint_uses_the_design_named_package(): void
    {
        $this->postJson('/api/rules/rules.subscription.activate/evaluate', ['facts' => ['outstandingBalance' => 10]])
            ->assertOk()
            ->assertJsonPath('decision.eligible', false)
            ->assertJsonPath('decision.decisionCode', 'PAY_FIRST_REQUIRED');
    }

    public function test_operator_can_override_a_rule_package_without_code(): void
    {
        // Tanzania deploys a more lenient activation policy for the SAME package.
        $this->postJson('/api/rules/decision-tables', [
            'rule_set' => 'rules.subscription.activate',
            'name' => 'Activation (WTZ)',
            'operator_code' => 'WTZ',
            'hit_policy' => 'FIRST',
            'rules' => [],
            'default_output' => ['eligible' => true],
        ])->assertCreated();

        $this->postJson('/api/rules/rules.subscription.activate/evaluate', ['facts' => ['outstandingBalance' => 999]], ['X-Operator-Code' => 'WIK'])
            ->assertOk()->assertJsonPath('decision.eligible', false);

        $this->postJson('/api/rules/rules.subscription.activate/evaluate', ['facts' => ['outstandingBalance' => 999]], ['X-Operator-Code' => 'WTZ'])
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
