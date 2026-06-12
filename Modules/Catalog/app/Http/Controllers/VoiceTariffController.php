<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\VoiceDestinationPrefix;
use Modules\Catalog\Models\VoiceDestinationZone;
use Modules\Catalog\Models\VoiceTariffAllowance;
use Modules\Catalog\Models\VoiceTariffBinding;
use Modules\Catalog\Models\VoiceTariffPlan;
use Modules\Catalog\Models\VoiceTariffRate;
use Modules\Catalog\Models\VoiceTimeBand;
use Modules\Catalog\Services\VoiceTariffService;

/**
 * PLM-CFG-07 Voice Tariff Catalog API (DD §8). Admin CRUD + lifecycle for plans,
 * zones, prefixes, time bands, rates, allowances, bindings, plus the rating-lookup
 * read used by RAT-01. Reads require catalog.read; writes require catalog.manage.
 */
class VoiceTariffController extends ApiController
{
    public function __construct(private readonly VoiceTariffService $service) {}

    // ----------------------------------------------------------------- Plans

    public function plans(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = VoiceTariffPlan::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tariff_plan_code' => ['required', 'string', 'max:128'],
            'display_name' => ['required', 'string', 'max:255'],
            'billing_mode' => ['required', 'in:PREPAID,POSTPAID,BOTH'],
            'currency_code' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->service->createPlan($data));
    }

    public function showPlan(VoiceTariffPlan $tariffPlan): JsonResponse
    {
        return ApiResponse::item($tariffPlan);
    }

    public function updatePlan(Request $request, VoiceTariffPlan $tariffPlan): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'billing_mode' => ['sometimes', 'in:PREPAID,POSTPAID,BOTH'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        return ApiResponse::item($this->service->updatePlan($tariffPlan, $data));
    }

    public function activatePlan(VoiceTariffPlan $tariffPlan): JsonResponse
    {
        return ApiResponse::item($this->service->activatePlan($tariffPlan));
    }

    public function retirePlan(VoiceTariffPlan $tariffPlan): JsonResponse
    {
        return ApiResponse::item($this->service->retirePlan($tariffPlan));
    }

    // ----------------------------------------------------------------- Zones

    public function zones(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = VoiceDestinationZone::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('zoneType'), fn ($q, $t) => $q->where('zone_type', $t))
            ->orderBy('zone_code')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zone_code' => ['required', 'string', 'max:128'],
            'zone_name' => ['required', 'string', 'max:255'],
            'zone_type' => ['required', 'in:ON_NET,NATIONAL,REGIONAL,INTERNATIONAL,TOLL_FREE,EMERGENCY,PREMIUM'],
            'default_charge_policy' => ['required', 'in:CHARGEABLE,ZERO_RATED,BLOCKED,QUARANTINE'],
        ]);

        return ApiResponse::created($this->service->createZone($data));
    }

    // ------------------------------------------------------------- Time bands

    public function timeBands(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = VoiceTimeBand::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderBy('time_band_code')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeTimeBand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'time_band_code' => ['required', 'string', 'max:128'],
            'display_name' => ['required', 'string', 'max:255'],
            'days_of_week' => ['required', 'string', 'max:255'],
            'start_time_local' => ['required', 'string'],
            'end_time_local' => ['required', 'string'],
            'timezone' => ['required', 'string', 'max:64'],
        ]);

        return ApiResponse::created($this->service->createTimeBand($data));
    }

    // -------------------------------------------------------------- Prefixes

    public function prefixes(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = VoiceDestinationPrefix::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storePrefix(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prefix' => ['required', 'string', 'max:64'],
            'zone_id' => ['required', 'string'],
            'match_priority' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::created($this->service->createPrefix($data));
    }

    public function bulkImportPrefixes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prefixes' => ['required', 'array', 'min:1'],
            'prefixes.*.prefix' => ['required', 'string', 'max:64'],
            'prefixes.*.zone_id' => ['required', 'string'],
            'prefixes.*.match_priority' => ['nullable', 'integer'],
            'prefixes.*.effective_from' => ['required', 'date'],
            'prefixes.*.effective_to' => ['nullable', 'date'],
            'prefixes.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $created = $this->service->bulkImportPrefixes($data['prefixes']);

        return ApiResponse::item(['imported' => count($created), 'items' => $created], 201);
    }

    // ----------------------------------------------------------------- Rates

    public function rates(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $operator = $request->query('operatorCode', Context::operatorCode());
        $page = VoiceTariffRate::query()
            ->where('operator_code', $operator)
            ->when($request->query('tariffPlanCode'), function ($q, $code) use ($operator) {
                $planIds = VoiceTariffPlan::query()
                    ->where('operator_code', $operator)
                    ->where('tariff_plan_code', $code)
                    ->pluck('tariff_plan_id');
                $q->whereIn('tariff_plan_id', $planIds);
            })
            ->orderByDesc('effective_from')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $data = $request->validate($this->rateRules());

        return ApiResponse::created($this->service->createRate($data));
    }

    public function bulkImportRates(Request $request): JsonResponse
    {
        $rules = ['rates' => ['required', 'array', 'min:1']];
        foreach ($this->rateRules() as $field => $r) {
            $rules["rates.*.{$field}"] = $r;
        }
        $data = $request->validate($rules);

        $created = $this->service->bulkImportRates($data['rates']);

        return ApiResponse::item(['imported' => count($created), 'items' => $created], 201);
    }

    public function validateOverlap(Request $request): JsonResponse
    {
        $rules = ['rates' => ['required', 'array', 'min:1']];
        foreach ($this->rateRules() as $field => $r) {
            $rules["rates.*.{$field}"] = $r;
        }
        $data = $request->validate($rules);

        $conflicts = $this->service->validateOverlap($data['rates']);

        return ApiResponse::item([
            'valid' => $conflicts === [],
            'conflicts' => $conflicts,
        ]);
    }

    // ------------------------------------------------------------ Allowances

    public function allowances(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = VoiceTariffAllowance::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('tariffPlanId'), fn ($q, $p) => $q->where('tariff_plan_id', $p))
            ->orderBy('priority')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeAllowance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tariff_plan_id' => ['required', 'string'],
            'allowance_code' => ['required', 'string', 'max:128'],
            'allowance_name' => ['required', 'string', 'max:255'],
            'included_seconds' => ['required', 'integer', 'min:0'],
            'eligible_zone_codes_json' => ['required', 'array'],
            'eligible_zone_codes_json.*' => ['string'],
            'cycle_policy' => ['required', 'in:MONTHLY,BILL_CYCLE,TOPUP_VALIDITY'],
            'carry_over_policy' => ['nullable', 'in:NONE,ONE_CYCLE,CONFIGURED'],
            'priority' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->service->createAllowance($data));
    }

    // -------------------------------------------------------------- Bindings

    public function bindings(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = VoiceTariffBinding::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('bindingScope'), fn ($q, $s) => $q->where('binding_scope', $s))
            ->orderByDesc('priority')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeBinding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'binding_scope' => ['required', 'in:PACKAGE,SERVICE,SUBSCRIPTION_OVERRIDE'],
            'binding_ref' => ['required', 'string', 'max:255'],
            'tariff_plan_id' => ['required', 'string'],
            'priority' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->service->createBinding($data));
    }

    // -------------------------------------------------------- Rating lookup

    public function ratingLookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'subscriptionId' => ['nullable', 'string'],
            'packageRef' => ['nullable', 'string'],
            'serviceRef' => ['nullable', 'string'],
            'calledNumberNormalized' => ['required', 'string'],
            'callDirection' => ['required', 'in:OUTBOUND,INBOUND,FORWARDED'],
            'callStartedAt' => ['required', 'date'],
        ]);

        return ApiResponse::item($this->service->ratingLookup($data));
    }

    /** @return array<string,array<int,string>> */
    private function rateRules(): array
    {
        return [
            'tariff_plan_id' => ['required', 'string'],
            'zone_id' => ['required', 'string'],
            'time_band_id' => ['required', 'string'],
            'call_direction' => ['required', 'in:OUTBOUND,INBOUND,FORWARDED'],
            'unit_type' => ['required', 'in:SECOND,MINUTE,CALL'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'setup_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'initial_increment_seconds' => ['nullable', 'integer', 'min:0'],
            'subsequent_increment_seconds' => ['nullable', 'integer', 'min:0'],
            'minimum_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'charge_policy' => ['nullable', 'in:CHARGEABLE,ZERO_RATED,BLOCKED,QUARANTINE'],
            'taxable_kind' => ['nullable', 'string', 'max:64'],
            'taxable_ref' => ['nullable', 'string', 'max:128'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
        ];
    }
}
