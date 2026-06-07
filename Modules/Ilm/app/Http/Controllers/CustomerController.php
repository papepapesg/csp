<?php

namespace Modules\Ilm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ilm\Http\Requests\StoreCustomerRequest;
use Modules\Ilm\Http\Requests\UpdateCustomerRequest;
use Modules\Ilm\Http\Resources\CustomerResource;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Services\CustomerService;

/**
 * Customer master API (ILM-CFG-01). ILM owns these writes; other modules read
 * via this API and must not touch the customer tables directly.
 */
class CustomerController extends ApiController
{
    public function __construct(private readonly CustomerService $customers) {}

    /**
     * GET /api/customers/search?key=msisdn&value=...&limit=
     * GET /api/customers?operatorCode=...
     */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);

        $query = Customer::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()));

        // Keyed search (DD_ILM-CFG-01 §4.1).
        $key = $request->query('key');
        $value = $request->query('value');
        if ($key && $value !== null) {
            match ($key) {
                'msisdn' => $query->where('primary_msisdn', $value),
                'email' => $query->where('email', $value),
                'name' => $query->where('name', 'like', "%{$value}%"),
                'id_number' => $query->where(fn ($q) => $q
                    ->where('identification_number_1', $value)
                    ->orWhere('identification_number_2', $value)),
                default => $query->where('customer_id', $value),
            };
        } elseif ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('primary_msisdn', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }

        [$sortField, $sortDir] = $params['sort'] ?? ['created_at', 'desc'];

        $page = $query->orderBy($sortField, $sortDir)
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page, fn ($c) => new CustomerResource($c));
    }

    /** POST /api/customers */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($request->validated());

        return ApiResponse::created(new CustomerResource($customer));
    }

    /** GET /api/customers/{customer} */
    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['accounts', 'contactMethods']);

        return ApiResponse::item(new CustomerResource($customer));
    }

    /** PATCH /api/customers/{customer} */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customers->update($customer, $request->validated());

        return ApiResponse::item(new CustomerResource($customer));
    }
}
