<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-07 §7.5 — price for a (plan, zone, time band, call direction) effective
 * window. Primary input for RAT-01 charge calculation. Rounding increments live on
 * the row (R-VOICE-TAR-09); rates must not overlap (R-VOICE-TAR-08).
 */
class VoiceTariffRate extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'voice_tariff_rate';

    protected $primaryKey = 'rate_id';

    protected string $idPrefix = 'vtr';

    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:6',
        'setup_fee_amount' => 'decimal:6',
        'minimum_charge_amount' => 'decimal:6',
        'initial_increment_seconds' => 'integer',
        'subsequent_increment_seconds' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}
