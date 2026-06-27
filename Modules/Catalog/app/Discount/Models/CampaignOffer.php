<?php

namespace Modules\Catalog\Discount\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-05 §6.2 campaign offer (discount/bundle/package/message). */
class CampaignOffer extends Model
{
    use HasPrefixedId;

    protected $table = 'promotion_campaign_offer';

    protected $primaryKey = 'offer_id';

    protected string $idPrefix = 'pco';

    protected $guarded = [];

    public $timestamps = true;
}
