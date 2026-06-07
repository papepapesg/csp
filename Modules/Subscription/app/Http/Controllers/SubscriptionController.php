<?php

namespace Modules\Subscription\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;

/**
 * SUB-LM-01 subscription master API.
 */
class SubscriptionController extends ApiController
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = Subscription::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('accountId'), fn ($q, $a) => $q->where('account_id', $a))
            ->when($request->query('customerId'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status_code', explode(',', $s)))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'account_id' => ['required', 'string'],
            'homepass_id' => ['required', 'string'],
            'package_ref' => ['required', 'string'],
            'package_version_id' => ['nullable', 'string'],
            'billing_mode' => ['nullable', 'in:POSTPAID,PREPAID'],
            'currency' => ['nullable', 'string', 'size:3'],
            'cycle_model' => ['nullable', 'in:CALENDAR,ANNIVERSARY'],
            'cycle_anchor_day' => ['nullable', 'integer', 'between:1,28'],
        ]);
        $data['created_by'] = $request->user()?->uid;

        $subscription = $this->subscriptions->create($data);

        return ApiResponse::created($subscription);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        return ApiResponse::item($subscription);
    }
}
