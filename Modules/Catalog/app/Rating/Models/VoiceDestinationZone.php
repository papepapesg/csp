<?php

namespace Modules\Catalog\Rating\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-07 §7.2 — destination zone grouping dialed numbers that share rating
 * behavior (KE_MOBILE, EMERGENCY, TOLL_FREE, PREMIUM, ...). default_charge_policy
 * drives zero-rating of emergency/toll-free (R-VOICE-TAR-04/05).
 */
class VoiceDestinationZone extends Model
{
    use HasPrefixedId;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'voice_destination_zone';

    protected $primaryKey = 'zone_id';

    protected string $idPrefix = 'vdz';

    protected $guarded = [];
}
