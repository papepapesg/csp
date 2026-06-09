<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-05 §6.4 channel governance row. */
class CampaignChannel extends Model
{
    use HasPrefixedId;

    protected $table = 'promotion_campaign_channel';

    protected $primaryKey = 'channel_id';

    protected string $idPrefix = 'pcc';

    protected $guarded = [];

    public $timestamps = true;
}
