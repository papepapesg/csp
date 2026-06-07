<?php

namespace Modules\Rules\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Id;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rules\Models\DecisionTable;

/**
 * FOUNDATION_DROOLS decision-table admin + test API. Policy editable at runtime.
 */
class DecisionTableController extends ApiController
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = DecisionTable::query()
            ->when($request->query('ruleSet'), fn ($q, $r) => $q->where('rule_set', $r))
            ->when($request->query('operatorCode'), fn ($q, $o) => $q->where('operator_code', $o))
            ->orderBy('rule_set')->orderByDesc('version')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function show(DecisionTable $decisionTable): JsonResponse
    {
        return ApiResponse::item($decisionTable);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rule_set' => ['required', 'string', 'max:96'],
            'name' => ['required', 'string', 'max:255'],
            'operator_code' => ['nullable', 'string', 'max:16'],
            'hit_policy' => ['nullable', 'in:FIRST,COLLECT'],
            'inputs' => ['nullable', 'array'],
            'rules' => ['present', 'array'],
            'default_output' => ['nullable', 'array'],
        ]);
        $version = (int) DecisionTable::query()
            ->where('rule_set', $data['rule_set'])
            ->where('operator_code', $data['operator_code'] ?? null)
            ->max('version') + 1;

        $table = DecisionTable::query()->create($data + [
            'table_id' => Id::make('dt'),
            'version' => $version,
            'status' => DecisionTable::DEPLOYED,
            'created_by' => $request->user()?->uid,
        ]);

        return ApiResponse::created($table);
    }

    public function update(Request $request, DecisionTable $decisionTable): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'hit_policy' => ['sometimes', 'in:FIRST,COLLECT'],
            'rules' => ['sometimes', 'array'],
            'default_output' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:DRAFT,DEPLOYED,RETIRED'],
        ]);
        $decisionTable->update($data);

        return ApiResponse::item($decisionTable);
    }

    /** POST /api/rules/{ruleSet}/evaluate — test a rule set against sample facts. */
    public function evaluate(Request $request, string $ruleSet): JsonResponse
    {
        $facts = $request->validate(['facts' => ['required', 'array']])['facts'];

        return ApiResponse::item(['ruleSet' => $ruleSet, 'decision' => $this->rules->evaluate($ruleSet, $facts)]);
    }
}
