<?php

namespace Modules\Subscription\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SUB-LM-01 subscription master. SUB-LM is the sole writer of this row; SUB-WF
 * mutates it only through SubscriptionService (HLD §6.2).
 *
 * @property string $subscription_id
 * @property string $status_code
 */
class Subscription extends Model
{
    use HasPrefixedId;

    public const CREATED = 'CREATED';

    public const PENDING_ACTIVATION = 'PENDING_ACTIVATION';

    public const ACTIVE = 'ACTIVE';

    public const PENDING_PAUSE = 'PENDING_PAUSE';

    public const PENDING_RESUME = 'PENDING_RESUME';

    public const PENDING_SUSPEND_NP = 'PENDING_SUSPEND_NP';

    public const SUSPENDED = 'SUSPENDED';

    /** @deprecated SUB-LM-01: pause now resolves to SUSPENDED (with a reason). Kept for back-compat. */
    public const PAUSED = 'PAUSED';

    public const RESTRICTED = 'RESTRICTED';

    public const PENDING_UPGRADE = 'PENDING_UPGRADE';

    public const PENDING_DOWNGRADE = 'PENDING_DOWNGRADE';

    public const PENDING_RELOCATION = 'PENDING_RELOCATION';

    public const PENDING_MIGRATION = 'PENDING_MIGRATION';

    public const PENDING_TERMINATION = 'PENDING_TERMINATION';

    public const TERMINATED = 'TERMINATED';

    public const RETIRED = 'RETIRED';

    protected $table = 'subscription';

    protected $primaryKey = 'subscription_id';

    protected string $idPrefix = 'sub';

    protected $guarded = [];

    protected $casts = [
        'active_restrictions' => 'array',
        'last_failure' => 'array',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'resumed_at' => 'datetime',
        'terminated_at' => 'datetime',
        'last_status_changed_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
        'current_cycle_start' => 'datetime',
        'current_cycle_end' => 'datetime',
        'last_cycle_closed_window_end' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    /** Length of one billing cycle, from the SUB-LM cycle attributes. */
    public function cyclePeriod(): \Carbon\CarbonInterval
    {
        return $this->cycle_period_days
            ? \Carbon\CarbonInterval::days((int) $this->cycle_period_days)
            : \Carbon\CarbonInterval::months(max(1, (int) ($this->cycle_frequency_months ?? 1)));
    }

    public function getRouteKeyName(): string
    {
        return 'subscription_id';
    }

    public function operations(): HasMany
    {
        return $this->hasMany(SubscriptionOperation::class, 'subscription_id', 'subscription_id');
    }

    public function isTerminal(): bool
    {
        return $this->status_code === self::TERMINATED;
    }
}
