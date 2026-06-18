<?php

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** PLM-CFG-07 per-operator usage rate (DATA per MB, SMS per message), now with the
 *  generic rating fields (reservation/pulse, allowance, fees, policy). */
class UsageTariff extends Model
{
    protected $table = 'usage_tariff';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'rate_per_unit' => 'decimal:4', 'active' => 'boolean',
        'initial_increment_units' => 'float', 'subsequent_increment_units' => 'float',
        'setup_fee' => 'float', 'min_charge' => 'float', 'included_units' => 'float',
    ];

    /** The active per-unit rate for an (operator, usage_type), or null to use the default. */
    public static function rate(string $operator, string $usageType): ?float
    {
        $row = static::query()->where('operator_code', $operator)->where('usage_type', $usageType)->where('active', true)->first();

        return $row ? (float) $row->rate_per_unit : null;
    }
}
