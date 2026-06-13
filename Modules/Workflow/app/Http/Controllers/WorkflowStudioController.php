<?php

namespace Modules\Workflow\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Id;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workflow\Engine\TaskRegistry;
use Modules\Workflow\Models\ProcessDefinition;

/**
 * Workflow studio API: the toolbox palette + CRUD/deploy of process definitions.
 * Backs the React Flow drag-and-drop designer. Flows are data; deploying a new
 * version never requires code (CAM-BPMN-*).
 */
class WorkflowStudioController extends ApiController
{
    public function __construct(private readonly TaskRegistry $registry) {}

    /** GET /api/workflow/palette — reusable steps available to drag onto a flow. */
    public function palette(): JsonResponse
    {
        // Each flow element carries a plain-language description ("what is this node for") so the
        // studio's inspector can explain it to a non-engineer designing a flow.
        $nodeTypes = [
            ['type' => 'startEvent', 'label' => 'Start', 'category' => 'Events',
                'description' => 'Where the process begins. A flow has exactly one start; the engine creates an instance here when the process is triggered.'],
            ['type' => 'endEvent', 'label' => 'End', 'category' => 'Events',
                'description' => 'Marks a path as finished. When every active path reaches an end, the process instance completes.'],
            ['type' => 'exclusiveGateway', 'label' => 'Gateway (decision)', 'category' => 'Gateways',
                'description' => 'A branch point: evaluates each outgoing path\'s condition in order and follows the first that matches (or the default). Use it to route on data, e.g. KYC approved vs rejected.'],
            ['type' => 'userTask', 'label' => 'Human task', 'category' => 'Tasks',
                'description' => 'Pauses the flow for a person to act (approve, review, call the customer). The instance waits until the task is completed from a worklist.'],
            ['type' => 'timer', 'label' => 'Timer', 'category' => 'Events',
                'description' => 'Waits for a duration or until a date before continuing — e.g. hold 24h before retrying, or wait for a grace period to elapse.'],
            ['type' => 'messageCatch', 'label' => 'Wait for message', 'category' => 'Events',
                'description' => 'Pauses until an external event/message arrives (e.g. a payment callback or a provisioning confirmation), then resumes the flow.'],
        ];

        return ApiResponse::item([
            'nodeTypes' => $nodeTypes,
            'steps' => $this->registry->palette(), // serviceTask topics from the toolbox
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = ProcessDefinition::query()
            ->when($request->query('processKey'), fn ($q, $k) => $q->where('process_key', $k))
            ->when($request->query('operatorCode'), fn ($q, $o) => $q->where('operator_code', $o))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderBy('process_key')->orderByDesc('version')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function show(ProcessDefinition $processDefinition): JsonResponse
    {
        return ApiResponse::item($processDefinition);
    }

    /** POST /api/workflow/definitions — create a new DRAFT (next version of a key). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'process_key' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'operator_code' => ['nullable', 'string', 'max:16'],
            'graph' => ['required', 'array'],
            'graph.nodes' => ['required', 'array'],
            'graph.edges' => ['present', 'array'],
        ]);

        $version = (int) ProcessDefinition::query()
            ->where('process_key', $data['process_key'])
            ->where('operator_code', $data['operator_code'] ?? null)
            ->max('version') + 1;

        $definition = ProcessDefinition::query()->create([
            'definition_id' => Id::make('pdef'),
            'process_key' => $data['process_key'],
            'version' => $version,
            'operator_code' => $data['operator_code'] ?? null,
            'name' => $data['name'],
            'graph' => $data['graph'],
            'status' => ProcessDefinition::DRAFT,
            'created_by' => $request->user()?->uid,
        ]);

        return ApiResponse::created($definition);
    }

    /** PUT /api/workflow/definitions/{def} — edit a DRAFT graph. */
    public function update(Request $request, ProcessDefinition $processDefinition): JsonResponse
    {
        if ($processDefinition->status !== ProcessDefinition::DRAFT) {
            return ApiResponse::error('CONFLICT', 'Only DRAFT definitions can be edited; deploy a new version instead.', 409);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'graph' => ['sometimes', 'array'],
        ]);
        $processDefinition->update($data);

        return ApiResponse::item($processDefinition);
    }

    /** POST /api/workflow/definitions/{def}/deploy — make it the active version. */
    public function deploy(ProcessDefinition $processDefinition): JsonResponse
    {
        // Retire the currently deployed definition for the same key+operator.
        ProcessDefinition::query()
            ->where('process_key', $processDefinition->process_key)
            ->where('operator_code', $processDefinition->operator_code)
            ->where('status', ProcessDefinition::DEPLOYED)
            ->update(['status' => ProcessDefinition::RETIRED]);

        $processDefinition->update(['status' => ProcessDefinition::DEPLOYED, 'deployed_at' => now()]);

        return ApiResponse::item($processDefinition);
    }
}
