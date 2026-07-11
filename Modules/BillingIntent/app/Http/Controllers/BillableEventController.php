<?php

namespace Modules\Billing\Intent\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Intent\Models\BillableEvent;
use Modules\Billing\Intent\Models\BillableEventCategory;
use Modules\Billing\Intent\Services\BillableEventCatalogService;
use App\Foundation\Catalog\SupportLevel;
use Illuminate\Validation\Rule;

/**
 * BIL-CFG-01 BillableEvent catalog admin API (DD §4): CRUD + DRAFT→ACTIVE→RETIRED
 * lifecycle, operator-scoped. BIL-01 reads this catalog at charge time.
 */
class BillableEventController extends ApiController
{
    public function __construct(private readonly BillableEventCatalogService $catalog) {}

    /** GET /api/billing/billable-events?triggerType=&triggerIntentCode=&status= */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = BillableEvent::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('triggerType'), fn ($q, $t) => $q->where('trigger_type', $t))
            ->when($request->query('triggerIntentCode'), fn ($q, $i) => $q->where('trigger_intent_code', $i))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('categoryCode'), fn ($q, $c) => $q->where('category_code', $c))
            ->when($request->query('supportLevel'), fn ($q, $s) => $q->where('support_level', $s))
            ->orderBy('display_order')->orderBy('code')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/billing/billable-events/{billableEvent} */
    public function show(BillableEvent $billableEvent): JsonResponse
    {
        return ApiResponse::item($billableEvent);
    }

    /** GET /api/billing/billable-event-categories */
    public function categories(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => BillableEventCategory::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderBy('display_order')->orderBy('code')->get()]);
    }

    /** POST /api/billing/billable-events */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        return ApiResponse::item($this->catalog->create($data, $request->user()?->uid), 201);
    }

    /** PATCH /api/billing/billable-events/{billableEvent} */
    public function update(Request $request, BillableEvent $billableEvent): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        return ApiResponse::item($this->catalog->update($billableEvent, $data, $request->user()?->uid));
    }

    /** POST /api/billing/billable-events/{billableEvent}/activate */
    public function activate(Request $request, BillableEvent $billableEvent): JsonResponse
    {
        return ApiResponse::item($this->catalog->activate($billableEvent, $request->user()?->uid));
    }

    /** POST /api/billing/billable-events/{billableEvent}/retire */
    public function retire(Request $request, BillableEvent $billableEvent): JsonResponse
    {
        return ApiResponse::item($this->catalog->retire($billableEvent, $request->user()?->uid));
    }

    /** @return array<string, array<int, string>> */
    private function rules(bool $required): array
    {
        $base = $required ? 'required' : 'sometimes';

        return [
            'code' => [$base, 'string', 'max:64'],
            'description' => [$base, 'string', 'max:255'],
            'category_code' => [$base, 'string'],
            'trigger_type' => [$base, 'string'],
            'service_refs' => ['nullable', 'array'],
            'currency' => ['nullable', 'string', 'size:3'],
            'applicability' => ['nullable', 'string'],
            'amount_sign_policy' => ['nullable', 'string'],
            'pay_first_required' => ['nullable', 'boolean'],
            'trigger_intent_code' => ['nullable', 'string'],
            'trigger_event_type' => ['nullable', 'string'],
            'trigger_filter_drl' => ['nullable', 'string'],
            'trigger_schedule' => ['nullable', 'string'],
            'state_callback' => ['nullable', 'array'],
            'eligibility_franchise_refs' => ['nullable', 'array'],
            'eligibility_package_refs' => ['nullable', 'array'],
            'eligibility_segment_refs' => ['nullable', 'array'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'support_level' => ['nullable', Rule::enum(SupportLevel::class)],
            'behavior_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
