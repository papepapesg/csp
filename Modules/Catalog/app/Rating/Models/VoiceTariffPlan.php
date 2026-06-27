<?php

namespace Modules\Catalog\Rating\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-07 §7.1 — commercial voice tariff plan. Lifecycle DRAFT → ACTIVE →
 * RETIRED; versioned with effective dates (R-VOICE-TAR-02). Owns rates, allowances,
 * and is selected at rating time via voice_tariff_binding.
 */
class VoiceTariffPlan extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'voice_tariff_plan';

    protected $primaryKey = 'tariff_plan_id';

    protected string $idPrefix = 'vtp';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}
