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

    public const PENDING_ACTIVATION = 'PENDING_ACTIVATION';

    public const ACTIVE = 'ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    public const PAUSED = 'PAUSED';

    public const RESTRICTED = 'RESTRICTED';

    public const PENDING_TERMINATION = 'PENDING_TERMINATION';

    public const TERMINATED = 'TERMINATED';

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
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
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
