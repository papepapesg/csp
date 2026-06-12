<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-07 §7.4 — time-of-day / day-of-week band used by rates (peak/off-peak,
 * weekend). The band whose window contains callStartedAt is selected during rating;
 * ANYTIME is the default catch-all.
 */
class VoiceTimeBand extends Model
{
    use HasPrefixedId;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'voice_time_band';

    protected $primaryKey = 'time_band_id';

    protected string $idPrefix = 'vtb';

    protected $guarded = [];
}
