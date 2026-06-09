<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-05 §6.3 campaign targeting rule. */
class CampaignTargetRule extends Model
{
    use HasPrefixedId;

    protected $table = 'promotion_campaign_target_rule';

    protected $primaryKey = 'target_rule_id';

    protected string $idPrefix = 'pct';

    protected $guarded = [];

    protected $casts = ['rule_value_json' => 'array', 'hard_exclusion' => 'boolean'];

    public $timestamps = true;
}
