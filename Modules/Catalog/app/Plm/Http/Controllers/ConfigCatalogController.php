<?php

namespace Modules\Catalog\Plm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Plm\Models\AdjustmentType;
use Modules\Catalog\Rating\Models\VoiceTariff;
use Modules\Catalog\Wallet\Models\WalletType;

/**
 * Generic CRUD for the simple operator-scoped PLM config catalogs (wallet,
 * adjustment-type, voice-tariff, equipment-type). One controller, one validation
 * map per catalog — config rows, not code.
 */
class ConfigCatalogController extends ApiController
{
    /** catalog key -> [model class, id prefix, validation rules] */
    private const CATALOGS = [
        'wallet-types' => [WalletType::class, 'wtyp', ['currency' => ['nullable', 'string', 'size:3'], 'allow_negative' => ['nullable', 'boolean'], 'auto_debit' => ['nullable', 'boolean']]],
        'adjustment-types' => [AdjustmentType::class, 'atyp', ['direction' => ['required', 'in:CREDIT,DEBIT'], 'requires_approval' => ['nullable', 'boolean'], 'gl_code' => ['nullable', 'string'], 'taxable' => ['nullable', 'boolean']]],
        'voice-tariffs' => [VoiceTariff::class, 'vtar', ['destination' => ['required', 'in:ONNET,OFFNET,INTERNATIONAL'], 'rate_per_min' => ['nullable', 'numeric'], 'setup_fee' => ['nullable', 'numeric'], 'min_charge_seconds' => ['nullable', 'integer']]],
        // equipment-types consolidated into OSR equipment_sku (the owner). Manage SKUs via OSR /api/equipment-skus.
    ];

    private function catalog(string $key): array
    {
        abort_unless(isset(self::CATALOGS[$key]), 404, "Unknown catalog [{$key}].");

        return self::CATALOGS[$key];
    }

    public function index(Request $request, string $catalog): JsonResponse
    {
        [$model] = $this->catalog($catalog);

        return ApiResponse::item(['items' => $model::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->orderBy('code')->get()]);
    }

    public function store(Request $request, string $catalog): JsonResponse
    {
        [$model, $prefix, $extra] = $this->catalog($catalog);
        $data = $request->validate(array_merge([
            'code' => ['required', 'string', 'max:48'],
            'name' => ['required', 'string', 'max:120'],
        ], $extra));
        $pk = (new $model)->getKeyName();

        return ApiResponse::created($model::query()->create($data + [$pk => Id::make($prefix)]));
    }
}
