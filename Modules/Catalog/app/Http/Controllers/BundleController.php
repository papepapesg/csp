<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\BundleMigrationRule;
use Modules\Catalog\Models\CommercialBundle;
use Modules\Catalog\Services\BundleService;

/** SIP-04 bundle launch API (§7). */
class BundleController extends ApiController
{
    public function __construct(private readonly BundleService $bundles) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = CommercialBundle::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->with('components')
            ->orderBy('bundle_code')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bundle_code' => ['required', 'string', 'max:64'],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'bundle_type' => ['nullable', 'in:ACQUISITION,RETENTION,MIGRATION,BUSINESS,STAFF,GENERAL'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'launch_date' => ['nullable', 'date'],
            'retire_date' => ['nullable', 'date'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.package_ref' => ['required', 'string'],
            'components.*.component_role' => ['nullable', 'in:PRIMARY,ADDON,OPTIONAL,PROMOTIONAL'],
            'components.*.mandatory' => ['nullable', 'boolean'],
            'components.*.quantity' => ['nullable', 'integer', 'min:1'],
            'availability' => ['nullable', 'array'],
            'availability.*.channel_code' => ['required_with:availability', 'in:BACKOFFICE,SALES_APP,SELF_CARE,USSD,PARTNER_API'],
            'availability.*.region_code' => ['nullable', 'string'],
            'availability.*.franchise_id' => ['nullable', 'string'],
            'availability.*.effective_from' => ['nullable', 'date'],
            'discount_rules' => ['nullable', 'array'],
            'discount_rules.*.discount_code' => ['required_with:discount_rules', 'string'],
        ]);
        $data['created_by'] = $request->user()?->uid;

        return ApiResponse::created($this->bundles->create($data));
    }

    public function show(CommercialBundle $bundle): JsonResponse
    {
        return ApiResponse::item($bundle->load('components', 'availability', 'discountRules', 'launchChecks'));
    }

    public function validateBundle(CommercialBundle $bundle): JsonResponse
    {
        return ApiResponse::item(['checks' => $this->bundles->validate($bundle)]);
    }

    public function submitReview(CommercialBundle $bundle): JsonResponse
    {
        return ApiResponse::item($this->bundles->submitForReview($bundle));
    }

    public function approve(Request $request, CommercialBundle $bundle): JsonResponse
    {
        $data = $request->validate(['decisionComment' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->bundles->approve($bundle, $data['decisionComment'] ?? null, $request->user()?->uid));
    }

    public function activate(CommercialBundle $bundle): JsonResponse
    {
        return ApiResponse::item($this->bundles->activate($bundle));
    }

    public function retire(CommercialBundle $bundle): JsonResponse
    {
        return ApiResponse::item($this->bundles->retire($bundle));
    }

    /** GET /api/commercial-bundles/available — R-SIP-BUN-10 channel/region/franchise gating. */
    public function available(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channelCode' => ['required', 'in:BACKOFFICE,SALES_APP,SELF_CARE,USSD,PARTNER_API'],
            'franchiseId' => ['nullable', 'string'],
            'regionCode' => ['nullable', 'string'],
        ]);

        return ApiResponse::item(['bundles' => $this->bundles->available($data['channelCode'], $data['franchiseId'] ?? null, $data['regionCode'] ?? null)]);
    }

    /** POST /api/commercial-bundles/{bundle}/migration-rules */
    public function storeMigrationRule(Request $request, CommercialBundle $bundle): JsonResponse
    {
        $data = $request->validate([
            'target_bundle_code' => ['required', 'string'],
            'movement_type' => ['required', 'in:UPGRADE,DOWNGRADE,MIGRATION,RETENTION,FORCED_RETIREMENT'],
            'allowed_channel_json' => ['nullable', 'array'],
            'requires_customer_consent' => ['nullable', 'boolean'],
            'requires_wo' => ['nullable', 'boolean'],
            'fee_policy_code' => ['nullable', 'string', 'max:32'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $target = CommercialBundle::query()->where('operator_code', $bundle->operator_code)
            ->where('bundle_code', $data['target_bundle_code'])->firstOrFail();

        $rule = BundleMigrationRule::query()->create([
            'operator_code' => $bundle->operator_code,
            'source_bundle_id' => $bundle->bundle_id,
            'target_bundle_id' => $target->bundle_id,
            'movement_type' => $data['movement_type'],
            'allowed_channel_json' => $data['allowed_channel_json'] ?? null,
            'requires_customer_consent' => $data['requires_customer_consent'] ?? true,
            'requires_wo' => $data['requires_wo'] ?? false,
            'fee_policy_code' => $data['fee_policy_code'] ?? 'NO_FEE',
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
        ]);

        return ApiResponse::created($rule);
    }

    /** POST /api/commercial-bundles/migration-preview (§7.7). */
    public function migrationPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sourceBundleCode' => ['required', 'string'],
            'targetBundleCode' => ['required', 'string'],
            'channelCode' => ['required', 'string'],
        ]);

        return ApiResponse::item($this->bundles->migrationPreview($data['sourceBundleCode'], $data['targetBundleCode'], $data['channelCode']));
    }
}
