<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PLM-CFG-04 / SIP-03 / SIP-05 — promo_campaign. */
class PromoCampaign extends Model
{
    use HasPrefixedId;

    protected $table = 'promo_campaign';

    protected $primaryKey = 'campaign_id';

    protected string $idPrefix = 'camp';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    protected $casts = ['effective_from' => 'datetime', 'effective_until' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'stackable' => 'boolean', 'active' => 'boolean', 'value' => 'decimal:4'];
}
