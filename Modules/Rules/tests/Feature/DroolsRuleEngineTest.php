<?php

namespace Modules\Rules\Tests\Feature;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Rules\Engine\DataDrivenRuleEngine;
use Modules\Rules\Engine\DroolsRuleEngine;
use Tests\TestCase;

/**
 * FOUNDATION_DROOLS driver: same RuleEngine contract over KIE Server HTTP (§9),
 * pinned containers (DROOLS-VER-5), §10 result mapping, and CALL-4 fallback
 * behavior — plus the native engine's in-process memo (rules are the module's
 * own data, so Redis is off-limits; the memo kills the per-eval DB hit).
 */
class DroolsRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private function droolsEngine(): DroolsRuleEngine
    {
        config([
            'sophix.rules_driver' => 'drools',
            'sophix.drools.base_url' => 'http://kie.test/kie-server',
            'sophix.drools.containers' => [
                'rules.subscription' => [
                    'container' => 'subscription-rules-{operator}_1.0.0',
                    'lookup' => 'subscription-session',
                    'fact_class' => 'com.sophix.subscription.facts.RequestFact',
                ],
            ],
        ]);
        Context::setOperatorCode('WIK');

        return new DroolsRuleEngine;
    }

    public function test_kie_invocation_contract_and_decision_result_mapping(): void
    {
        $engine = $this->droolsEngine();
        Http::fake(['kie.test/*' => Http::response([
            'type' => 'SUCCESS',
            'result' => ['execution-results' => ['results' => ['results' => ['value' => [
                ['com.sophix.common.rules.DecisionResult' => [
                    'decisionCode' => 'PAY_FIRST_REQUIRED', 'ruleId' => 'R-BIL-001',
                    'attributes' => ['amount' => '1500.00', 'eligible' => false],
                ]],
                ['com.sophix.common.rules.ValidationError' => [
                    'ruleId' => 'R-SUB-ACT-001', 'field' => 'statusCode', 'message' => 'Must be CREATED',
                ]],
            ]]]]],
        ])]);

        $result = $engine->assess('rules.subscription.activate', ['statusCode' => 'ACTIVE', 'operatorCode' => 'WIK']);

        // §10 result classes map onto the native engine's contract shape.
        $this->assertSame('PAY_FIRST_REQUIRED', $result['decision']['decisionCode']);
        $this->assertSame('R-BIL-001', $result['decision']['ruleId']);
        $this->assertSame('1500.00', $result['decision']['amount']); // attributes merged flat
        $this->assertSame('statusCode', $result['validationErrors'][0]['field']);
        $this->assertEqualsCanonicalizing(['R-BIL-001', 'R-SUB-ACT-001'], $result['firedRules']);

        // §9 request shape: pinned operator container, session lookup, fact insert + fire + get.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/containers/instances/subscription-rules-wik_1.0.0')
                && $request->hasHeader('X-KIE-ContentType', 'JSON')
                && $request['lookup'] === 'subscription-session'
                && isset($request['commands'][0]['insert']['object']['com.sophix.subscription.facts.RequestFact'])
                && isset($request['commands'][1]['fire-all-rules'])
                && isset($request['commands'][2]['get-objects']);
        });
    }

    public function test_kie_unavailable_uses_registered_fallback_or_fails_as_dependency(): void
    {
        $engine = $this->droolsEngine();
        Http::fake(['kie.test/*' => Http::response('boom', 503)]);

        // DROOLS-CALL-4: a registered fallback answers when KIE is down…
        $engine->register('rules.subscription.activate', fn (array $facts) => ['eligible' => true, 'ruleId' => 'FALLBACK']);
        $decision = $engine->evaluate('rules.subscription.activate', ['operatorCode' => 'WIK']);
        $this->assertSame('FALLBACK', $decision['ruleId']);

        // …and without one, the caller sees a retryable dependency failure (503).
        try {
            $engine->evaluate('rules.subscription.pause', ['operatorCode' => 'WIK']);
            $this->fail('expected dependency failure');
        } catch (DomainException $e) {
            $this->assertSame(503, $e->status);
            $this->assertTrue($e->retryable);
        }
    }

    public function test_unpinned_rule_set_is_rejected_not_floated(): void
    {
        $engine = $this->droolsEngine();

        // DROOLS-VER-5: no pinned container for this set → explicit error, no guessing.
        try {
            $engine->evaluate('rules.osr.swap', ['operatorCode' => 'WIK']);
            $this->fail('expected RULE_PACKAGE_NOT_FOUND');
        } catch (DomainException $e) {
            $this->assertSame('RULE_PACKAGE_NOT_FOUND', $e->errorCode);
        }
    }

    public function test_driver_binding_follows_config(): void
    {
        config(['sophix.rules_driver' => 'drools']);
        (new \Modules\Rules\Providers\RulesRuntimeProvider(app()))->register();
        $this->assertInstanceOf(DroolsRuleEngine::class, app(\App\Foundation\Rules\RuleEngine::class));

        config(['sophix.rules_driver' => 'native']);
        (new \Modules\Rules\Providers\RulesRuntimeProvider(app()))->register();
        $this->assertInstanceOf(DataDrivenRuleEngine::class, app(\App\Foundation\Rules\RuleEngine::class));
    }

    public function test_native_engine_memo_avoids_repeated_table_queries(): void
    {
        config(['sophix.rules.memo_seconds' => 60]);
        $this->seed(\Modules\Rules\Database\Seeders\DecisionTableSeeder::class);
        Context::setOperatorCode('WIK');
        $engine = new DataDrivenRuleEngine;

        $engine->evaluate('rules.subscription.activate', ['outstandingBalance' => 0]);

        DB::enableQueryLog();
        for ($i = 0; $i < 50; $i++) {
            $engine->evaluate('rules.subscription.activate', ['outstandingBalance' => $i]);
        }
        $tableQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'decision_table'))->count();
        DB::disableQueryLog();

        // 50 evaluations in a worker loop, zero decision_table reads.
        $this->assertSame(0, $tableQueries);

        // forgetMemo() (used by the studio on writes) forces a fresh resolution.
        $engine->forgetMemo();
        DB::enableQueryLog();
        $engine->evaluate('rules.subscription.activate', ['outstandingBalance' => 1]);
        $this->assertSame(1, collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'decision_table'))->count());
        DB::disableQueryLog();
    }
}
