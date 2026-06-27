<?php

namespace Modules\Catalog\Rating\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-07 §7.3 — maps a normalized dialed-number prefix to a destination zone.
 * Resolved by longest-prefix match at rating time (R-VOICE-TAR-03), tie-broken by
 * match_priority.
 */
class VoiceDestinationPrefix extends Model
{
    use HasPrefixedId;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'voice_destination_prefix';

    protected $primaryKey = 'prefix_id';

    protected string $idPrefix = 'vdp';

    protected $guarded = [];

    protected $casts = [
        'match_priority' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}
