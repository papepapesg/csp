<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-03 discount assignment — a governed grant of a PLM-CFG-04 discount to a target scope.
 * Lifecycle: DRAFT/PENDING_APPROVAL -> ACTIVE -> SUSPENDED/EXPIRED/CANCELLED (or REJECTED).
 * SIP-03 governs the record; DIS-OP-01 reads ACTIVE rows at billing time and applies the money.
 *
 * @property string $assignment_id
 * @property string $status
 */
class DiscountAssignment extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';
    public const PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const ACTIVE = 'ACTIVE';
    public const SUSPENDED = 'SUSPENDED';
    public const EXPIRED = 'EXPIRED';
    public const CANCELLED = 'CANCELLED';
    public const REJECTED = 'REJECTED';

    protected $table = 'discount_assignment';

    protected $primaryKey = 'assignment_id';

    protected string $idPrefix = 'dasg';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    protected $casts = [
        'effective_from' => 'datetime', 'effective_until' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'valid_from' => 'date', 'valid_to' => 'date', 'activated_at' => 'datetime', 'cancelled_at' => 'datetime',
        'stackable' => 'boolean', 'active' => 'boolean', 'value' => 'decimal:4', 'metadata_json' => 'array', 'assignment_priority' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'assignment_id';
    }

    /**
     * The EFFECTIVE applicability the back-office should see — not the raw status — so a
     * CAMPAIGN-mode grant whose campaign is off reads as blocked-with-reason instead of a
     * misleading "active". Pass the row's PromoCampaign for CAMPAIGN mode (null otherwise).
     *
     * @return array{state:string, reason:?string}
     */
    public function applicabilityState(?PromoCampaign $campaign = null, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        if ($this->status !== self::ACTIVE) {
            return ['state' => 'INACTIVE', 'reason' => 'STATUS_'.$this->status];
        }
        if (($this->assignment_mode ?? 'DIRECT') !== 'CAMPAIGN') {
            return ['state' => 'APPLYING', 'reason' => null]; // direct: gated only by its own validity (already ACTIVE)
        }
        if (! $campaign) {
            return ['state' => 'BLOCKED_NO_CAMPAIGN', 'reason' => 'CAMPAIGN_MISSING'];
        }
        // Ended — either by status or by passing the window.
        if ($campaign->status === PromoCampaign::ENDED || ($campaign->ends_at && $at->gt($campaign->ends_at))) {
            return ['state' => 'BLOCKED_CAMPAIGN_ENDED', 'reason' => 'ENDED_'.($campaign->ends_at?->toDateString() ?? $campaign->status)];
        }
        if ($campaign->starts_at && $at->lt($campaign->starts_at)) {
            return ['state' => 'BLOCKED_CAMPAIGN_NOT_STARTED', 'reason' => 'STARTS_'.$campaign->starts_at->toDateString()];
        }
        if ($campaign->status !== PromoCampaign::ACTIVE) {
            return ['state' => 'BLOCKED_CAMPAIGN_PAUSED', 'reason' => 'CAMPAIGN_'.$campaign->status];
        }

        return ['state' => 'APPLYING', 'reason' => null];
    }
}
