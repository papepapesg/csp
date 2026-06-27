<?php

namespace Modules\Catalog\Models;
use Modules\Catalog\Models\CampaignChannel;
use Modules\Catalog\Models\CampaignOffer;
use Modules\Catalog\Models\CampaignParticipation;
use Modules\Catalog\Models\CampaignTargetRule;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SIP-05 promotional campaign master (lifecycle §5; offers/targeting/channels/participation). */
class PromoCampaign extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    public const APPROVED = 'APPROVED';

    public const ACTIVE = 'ACTIVE';

    public const PAUSED = 'PAUSED';

    public const ENDED = 'ENDED';

    protected $table = 'promo_campaign';

    protected $primaryKey = 'campaign_id';

    protected string $idPrefix = 'camp';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    protected $casts = ['effective_from' => 'datetime', 'effective_until' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'stackable' => 'boolean', 'active' => 'boolean', 'value' => 'decimal:4', 'budget_limit_amount' => 'decimal:2', 'max_participants' => 'integer'];

    public function offers(): HasMany
    {
        return $this->hasMany(CampaignOffer::class, 'campaign_id', 'campaign_id');
    }

    public function targetRules(): HasMany
    {
        return $this->hasMany(CampaignTargetRule::class, 'campaign_id', 'campaign_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(CampaignChannel::class, 'campaign_id', 'campaign_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(CampaignParticipation::class, 'campaign_id', 'campaign_id');
    }
}
