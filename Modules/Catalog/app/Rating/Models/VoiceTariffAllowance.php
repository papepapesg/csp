<?php

namespace Modules\Catalog\Rating\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-07 §7.6 — included / bundled voice minutes for a plan. Eligibility is
 * explicit by tariff plan and destination zone (R-VOICE-TAR-10). Definition only;
 * RAT-01 performs consumption.
 */
class VoiceTariffAllowance extends Model
{
    use HasPrefixedId;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'voice_tariff_allowance';

    protected $primaryKey = 'allowance_id';

    protected string $idPrefix = 'vta';

    protected $guarded = [];

    protected $casts = [
        'eligible_zone_codes_json' => 'array',
        'included_seconds' => 'integer',
        'priority' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}
