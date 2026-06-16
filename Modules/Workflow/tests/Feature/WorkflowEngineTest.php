<?php

namespace Modules\Workflow\Tests\Feature;

use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Workflow\Contracts\Io;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\Workflow\Engine\GraphValidator;
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
        $registry->register(ProduceStep::class);
        $registry->register(ConsumeStep::class);
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

    public function test_input_mapping_wires_an_upstream_output_into_a_downstream_input(): void
    {
        // produce -> consume, where consume.amount is WIRED from produce.priceDelta.
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'data' => []],
                ['id' => 'p', 'type' => 'serviceTask', 'data' => ['topic' => 'test.produce']],
                ['id' => 'c', 'type' => 'serviceTask', 'data' => ['topic' => 'test.consume',
                    'inputMappings' => ['amount' => ['from' => 'p.priceDelta']]]],
                ['id' => 'end', 'type' => 'endEvent', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'p'],
                ['id' => 'e2', 'source' => 'p', 'target' => 'c'],
                ['id' => 'e3', 'source' => 'c', 'target' => 'end'],
            ],
        ];
        $this->deploy('io-flow', $graph);

        app(WorkflowEngine::class)->start('io-flow', 'bk-io', [], 'WIK');
        $this->drain();

        // The consumer saw the produced value flow through the wire (not via a shared name).
        $this->assertContains('amount:1500', RecordSink::$seen);
        $this->assertSame(1, ProcessInstance::where('status', 'COMPLETED')->count());
    }

    public function test_graph_validator_passes_a_well_formed_wired_flow(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'data' => []],
                ['id' => 'p', 'type' => 'serviceTask', 'data' => ['topic' => 'test.produce']],
                ['id' => 'c', 'type' => 'serviceTask', 'data' => ['topic' => 'test.consume',
                    'inputMappings' => ['amount' => ['from' => 'p.priceDelta']]]],
                ['id' => 'end', 'type' => 'endEvent', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'p'],
                ['id' => 'e2', 'source' => 'p', 'target' => 'c'],
                ['id' => 'e3', 'source' => 'c', 'target' => 'end'],
            ],
        ];

        $this->assertSame([], app(GraphValidator::class)->validate($graph));
    }

    public function test_graph_validator_flags_required_input_unknown_topic_and_bad_wire(): void
    {
        $validator = app(GraphValidator::class);

        // (1) consume needs required 'amount' but nothing binds it.
        $missing = $validator->validate([
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'data' => []],
                ['id' => 'c', 'type' => 'serviceTask', 'data' => ['topic' => 'test.consume']],
                ['id' => 'end', 'type' => 'endEvent', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'c'],
                ['id' => 'e2', 'source' => 'c', 'target' => 'end'],
            ],
        ]);
        $this->assertNotEmpty($missing);
        $this->assertStringContainsString("missing required input 'amount'", implode(' ', $missing));

        // (2) unknown topic + (3) a wire onto an output the source does not produce.
        $bad = $validator->validate([
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'data' => []],
                ['id' => 'x', 'type' => 'serviceTask', 'data' => ['topic' => 'test.does-not-exist']],
                ['id' => 'p', 'type' => 'serviceTask', 'data' => ['topic' => 'test.produce']],
                ['id' => 'c', 'type' => 'serviceTask', 'data' => ['topic' => 'test.consume',
                    'inputMappings' => ['amount' => ['from' => 'p.nope']]]],
                ['id' => 'end', 'type' => 'endEvent', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'x'],
                ['id' => 'e2', 'source' => 'x', 'target' => 'p'],
                ['id' => 'e3', 'source' => 'p', 'target' => 'c'],
                ['id' => 'e4', 'source' => 'c', 'target' => 'end'],
            ],
        ]);
        $joined = implode(' ', $bad);
        $this->assertStringContainsString("unknown step 'test.does-not-exist'", $joined);
        $this->assertStringContainsString("does not produce 'nope'", $joined);
    }

    public function test_graph_validator_flags_a_wire_from_a_downstream_node(): void
    {
        // consume reads amount wired from `d`, but `d` runs AFTER consume — the
        // value cannot exist yet (producer/consumer ordering).
        $errors = app(GraphValidator::class)->validate([
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'data' => []],
                ['id' => 'c', 'type' => 'serviceTask', 'data' => ['topic' => 'test.consume',
                    'inputMappings' => ['amount' => ['from' => 'd.priceDelta']]]],
                ['id' => 'd', 'type' => 'serviceTask', 'data' => ['topic' => 'test.produce']],
                ['id' => 'end', 'type' => 'endEvent', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'c'],
                ['id' => 'e2', 'source' => 'c', 'target' => 'd'],
                ['id' => 'e3', 'source' => 'd', 'target' => 'end'],
            ],
        ]);

        $this->assertStringContainsString('not upstream', implode(' ', $errors));
    }

    public function test_strict_outputs_rejects_a_handler_that_returns_undeclared_keys(): void
    {
        config(['sophix.workflow.strict_outputs' => true]);
        app(TaskRegistry::class)->register(LeakyStep::class);

        $this->deploy('leak-flow', $this->linear('test.leaky'));
        app(WorkflowEngine::class)->start('leak-flow', 'bk-leak', [], 'WIK');
        $this->drain();

        // The undeclared 'b' makes lintOutputs throw -> the task never completes and
        // the instance fails (the bag is kept honest).
        $this->assertSame(0, ProcessInstance::where('status', 'COMPLETED')->count());
        $this->assertSame(1, ProcessInstance::where('status', 'FAILED')->count());
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

/** Declares an output port and publishes it (the producer side of a data wire). */
class ProduceStep implements TaskHandler
{
    public function topic(): string
    {
        return 'test.produce';
    }

    public function label(): string
    {
        return 'Test Produce';
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [Io::out('priceDelta', Io::NUMBER, 'A produced amount.')];
    }

    public function handle(TaskContext $c): TaskResult
    {
        return TaskResult::success(['priceDelta' => 1500]);
    }
}

/** Declares a required input and records the value the engine resolved for it. */
class ConsumeStep implements TaskHandler
{
    public function topic(): string
    {
        return 'test.consume';
    }

    public function label(): string
    {
        return 'Test Consume';
    }

    /** @return array<int,array<string,mixed>> */
    public function inputs(): array
    {
        return [Io::in('amount', Io::NUMBER, 'Amount to consume.', true)];
    }

    public function handle(TaskContext $c): TaskResult
    {
        RecordSink::$seen[] = 'amount:'.$c->input('amount');

        return TaskResult::success();
    }
}

/** Declares output 'a' but leaks an undeclared 'b' — drift the lint should catch. */
class LeakyStep implements TaskHandler
{
    public function topic(): string
    {
        return 'test.leaky';
    }

    public function label(): string
    {
        return 'Test Leaky';
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [Io::out('a', Io::STRING, 'The one declared output.')];
    }

    public function handle(TaskContext $c): TaskResult
    {
        return TaskResult::success(['a' => 'x', 'b' => 'y']);
    }
}
