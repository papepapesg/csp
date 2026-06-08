<?php

namespace Modules\Subscription\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SUB-WF-PAUSE-01 §7.2 pause/suspension period record. */
class SubscriptionPauseHistory extends Model
{
    use HasPrefixedId;

    protected $table = 'subscription_pause_history';

    protected $primaryKey = 'pause_id';

    protected string $idPrefix = 'pause';

    protected $guarded = [];

    protected $casts = [
        'system_managed' => 'boolean', 'admin_force_resume' => 'boolean',
        'suspended_at' => 'datetime', 'actual_resume_at' => 'datetime', 'resume_scheduled_at' => 'datetime',
        'outstanding_debt_amount' => 'decimal:2',
    ];

    /** The current open (not-yet-resumed) pause row for a subscription, if any. */
    public static function open(string $subscriptionId): ?self
    {
        return static::query()->where('subscription_id', $subscriptionId)->whereNull('actual_resume_at')->first();
    }
}
