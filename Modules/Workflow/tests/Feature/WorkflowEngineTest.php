<?php

namespace Modules\Workflow\Tests\Feature;

use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\Workflow\Engine\TaskRegistry;
use Modules\Workflow\Engine\WorkflowEngine;
use Modules\Workflow\Models\ProcessDefinition;
use Modules\Workflow\Models\ProcessInstance;
use Tests\TestCase;

/**
 * Proves the engine is config-driven: flow shape lives in DATA, and an operator
 * can override a flow with NO code change (the Tanzania scenario).
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Register two trivial recording steps into the toolbox for the test.
        $registry = app(TaskRegistry::class);
        $registry->register(RecordStepA::class);
        $registry->register(RecordStepB::class);
        RecordSink::$seen = [];
    }

    private function deploy(string $key, array $graph, ?string $operator = null): void
    {
        ProcessDefinition::query()->create([
            'definition_id' => Id::make('pdef'),
            'process_key' => $key,
            'version' => 1,
            'operator_code' => $operator,
            'name' => $key.($operator ? "-$operator" : ''),
            'graph' => $graph,
            'status' => ProcessDefinition::DEPLOYED,
            'deployed_at' => now(),
        ]);
    }

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    private function linear(string $topic): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'data' => []],
                ['id' => 't', 'type' => 'serviceTask', 'data' => ['topic' => $topic]],
                ['id' => 'end', 'type' => 'endEvent', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 't'],
                ['id' => 'e2', 'source' => 't', 'target' => 'end'],
            ],
        ];
    }

    public function test_operator_specific_flow_overrides_default_without_code(): void
    {
        // Same process key, two deployments: global runs step A, Tanzania runs step B.
        $this->deploy('demo-flow', $this->linear('test.step-a'));            // global default
        $this->deploy('demo-flow', $this->linear('test.step-b'), 'WTZ');     // operator override

        $engine = app(WorkflowEngine::class);

        $engine->start('demo-flow', 'bk-1', [], 'WIK');   // resolves global -> step A
        $engine->start('demo-flow', 'bk-2', [], 'WTZ');   // resolves WTZ -> step B
        $this->drain();

        $this->assertContains('A:bk-1', RecordSink::$seen);
        $this->assertContains('B:bk-2', RecordSink::$seen);
        $this->assertNotContains('A:bk-2', RecordSink::$seen);
        $this->assertSame(2, ProcessInstance::where('status', 'COMPLETED')->count());
    }

    public function test_exclusive_gateway_branches_on_variables(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'data' => []],
                ['id' => 'gw', 'type' => 'exclusiveGateway', 'data' => []],
                ['id' => 'a', 'type' => 'serviceTask', 'data' => ['topic' => 'test.step-a']],
                ['id' => 'b', 'type' => 'serviceTask', 'data' => ['topic' => 'test.step-b']],
                ['id' => 'enda', 'type' => 'endEvent', 'data' => []],
                ['id' => 'endb', 'type' => 'endEvent', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'gw'],
                ['id' => 'e2', 'source' => 'gw', 'target' => 'a', 'data' => ['condition' => ['var' => 'vip', 'op' => 'truthy']]],
                ['id' => 'e3', 'source' => 'gw', 'target' => 'b', 'data' => ['default' => true]],
                ['id' => 'e4', 'source' => 'a', 'target' => 'enda'],
                ['id' => 'e5', 'source' => 'b', 'target' => 'endb'],
            ],
        ];
        $this->deploy('branch-flow', $graph);
        $engine = app(WorkflowEngine::class);

        $engine->start('branch-flow', 'vip-1', ['vip' => true], 'WIK');
        $engine->start('branch-flow', 'reg-1', ['vip' => false], 'WIK');
        $this->drain();

        $this->assertContains('A:vip-1', RecordSink::$seen);
        $this->assertContains('B:reg-1', RecordSink::$seen);
    }
}

class RecordSink
{
    /** @var array<int,string> */
    public static array $seen = [];
}

class RecordStepA implements TaskHandler
{
    public function topic(): string
    {
        return 'test.step-a';
    }

    public function label(): string
    {
        return 'Test Step A';
    }

    public function handle(TaskContext $c): TaskResult
    {
        RecordSink::$seen[] = 'A:'.$c->businessKey();

        return TaskResult::success();
    }
}

class RecordStepB implements TaskHandler
{
    public function topic(): string
    {
        return 'test.step-b';
    }

    public function label(): string
    {
        return 'Test Step B';
    }

    public function handle(TaskContext $c): TaskResult
    {
        RecordSink::$seen[] = 'B:'.$c->businessKey();

        return TaskResult::success();
    }
}
