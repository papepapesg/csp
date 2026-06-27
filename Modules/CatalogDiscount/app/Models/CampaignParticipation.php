<?php

namespace Modules\Catalog\Discount\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-05 §6.5 per-participant eligibility/redemption record. */
class CampaignParticipation extends Model
{
    use HasPrefixedId;

    protected $table = 'promotion_campaign_participation';

    protected $primaryKey = 'participation_id';

    protected string $idPrefix = 'pcp';

    protected $guarded = [];

    protected $casts = ['redeemed_at' => 'datetime'];

    public $timestamps = true;
}
