<?php

namespace Modules\Ilm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ilm\Http\Requests\StoreAccountRequest;
use Modules\Ilm\Http\Resources\CustomerAccountResource;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Services\AccountService;

/**
 * Customer Account API (ILM-CFG-01 §2.2).
 */
class CustomerAccountController extends ApiController
{
    public function __construct(private readonly AccountService $accounts) {}

    /** GET /api/customer-accounts?customerId=... */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);

        $query = CustomerAccount::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()));

        if ($customerId = $request->query('customerId')) {
            $query->where('customer_id', $customerId);
        }
        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', $status));
        }

        $page = $query->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page, fn ($a) => new CustomerAccountResource($a));
    }

    /** POST /api/customer-accounts */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->create($request->validated());

        return ApiResponse::created(new CustomerAccountResource($account));
    }

    /** GET /api/customer-accounts/{account} */
    public function show(CustomerAccount $account): JsonResponse
    {
        return ApiResponse::item(new CustomerAccountResource($account));
    }

    /** PATCH /api/customer-accounts/{account} */
    public function update(Request $request, CustomerAccount $account): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:INACTIVE,ACTIVE'],
            'sub_status' => ['sometimes', 'string', 'max:64'],
            'sub_status_reason' => ['nullable', 'string', 'max:255'],
            'attention_banner' => ['nullable', 'string', 'max:255'],
            'service_class_1' => ['nullable', 'string', 'max:32'],
            'service_class_2' => ['nullable', 'string', 'max:32'],
            'service_class_3' => ['nullable', 'string', 'max:32'],
            'subscription_id' => ['nullable', 'string', 'max:64'],
            'account_manager_id' => ['nullable', 'string', 'max:64'],
        ]);

        $account = $this->accounts->update($account, $data);

        return ApiResponse::item(new CustomerAccountResource($account));
    }
}
