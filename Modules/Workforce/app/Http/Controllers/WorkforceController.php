<?php

namespace Modules\Workforce\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workforce\Models\Contractor;
use Modules\Workforce\Models\ContractorTeam;
use Modules\Workforce\Models\StaffMember;

/**
 * EM-02 Contractor & Staff Registry API.
 */
class WorkforceController extends ApiController
{
    public function contractors(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = Contractor::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeContractor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'in:INTERNAL,EXTERNAL'],
            'skills' => ['nullable', 'array'],
        ]);

        return ApiResponse::created(Contractor::query()->create($data));
    }

    public function storeTeam(Request $request, Contractor $contractor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'skills' => ['nullable', 'array'],
        ]);
        $data['contractor_id'] = $contractor->contractor_id;

        return ApiResponse::created(ContractorTeam::query()->create($data));
    }

    public function staff(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = StaffMember::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('contractorId'), fn ($q, $c) => $q->where('contractor_id', $c))
            ->when($request->query('teamId'), fn ($q, $t) => $q->where('team_id', $t))
            ->orderBy('name')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'in:TECHNICIAN,TEAM_LEAD,SUPERVISOR'],
            'contractor_id' => ['nullable', 'string'],
            'team_id' => ['nullable', 'string'],
            'msisdn' => ['nullable', 'string', 'max:32'],
            'skills' => ['nullable', 'array'],
        ]);

        return ApiResponse::created(StaffMember::query()->create($data));
    }
}
