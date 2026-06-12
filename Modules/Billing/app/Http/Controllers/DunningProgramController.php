<?php

namespace Modules\Billing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\DunningProgram;

/**
 * BIL-04 dunning program catalog admin (R-BIL-04-C-1). Versioned: a new-version call retires
 * the prior version and publishes the next; published versions are never mutated, so in-flight
 * episodes (which pin their version) are unaffected.
 */
class DunningProgramController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $items = DunningProgram::query()
            ->when($request->query('operatorCode'), fn ($q, $o) => $q->where('operator_code', $o))
            ->when($request->query('billingMode'), fn ($q, $m) => $q->where('billing_mode', $m))
            ->when($request->boolean('activeOnly'), fn ($q) => $q->whereNull('retired_at'))
            ->orderBy('code')->orderByDesc('version')->get();

        return ApiResponse::item(['items' => $items]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $program = DunningProgram::query()->where('code', $code)
            ->when($request->query('version'), fn ($q, $v) => $q->where('version', (int) $v), fn ($q) => $q->whereNull('retired_at'))
            ->orderByDesc('version')->firstOrFail();

        return ApiResponse::item($program);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateProgram($request);
        $program = DunningProgram::query()->create($data + [
            'operator_code' => $request->input('operator_code', Context::operatorCode()),
            'version' => 1, 'published_at' => now(), 'created_by' => $request->user()?->uid,
        ]);

        return ApiResponse::created($program);
    }

    /** POST /api/dunning-programs/{code}/new-version — retire the active version, publish the next. */
    public function newVersion(Request $request, string $code): JsonResponse
    {
        $data = $this->validateProgram($request, partial: true);
        $current = DunningProgram::query()->where('code', $code)->whereNull('retired_at')->orderByDesc('version')->firstOrFail();
        $next = DunningProgram::query()->where('code', $code)->max('version') + 1;

        $current->update(['retired_at' => now()]);
        $program = DunningProgram::query()->create([
            'code' => $code,
            'operator_code' => $current->operator_code,
            'billing_mode' => $current->billing_mode,
            'description' => $data['description'] ?? $current->description,
            'level_definitions' => $data['level_definitions'] ?? $current->level_definitions,
            'pre_termination_review_required' => $data['pre_termination_review_required'] ?? $current->pre_termination_review_required,
            'version' => $next, 'published_at' => now(), 'created_by' => $request->user()?->uid,
        ]);

        return ApiResponse::created($program);
    }

    /** @return array<string,mixed> */
    private function validateProgram(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'billing_mode' => [$req, 'in:POSTPAID,PREPAID,PREPAYMENT'],
            'level_definitions' => [$req, 'array', 'min:1'],
            'level_definitions.*.level' => ['required_with:level_definitions', 'integer'],
            'level_definitions.*.grace_period_days' => ['required_with:level_definitions', 'integer', 'min:0'],
            'level_definitions.*.action_workflow_intent' => ['required_with:level_definitions', 'in:WARNING_ONLY,RESTRICTION_ADD,SUSPEND_NP,TERMINATION'],
            'pre_termination_review_required' => ['nullable', 'boolean'],
        ]);
    }
}
