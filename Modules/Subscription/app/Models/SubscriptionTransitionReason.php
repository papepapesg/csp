<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/** SUB-LM-01 §5.3 transition-reason catalog. */
class SubscriptionTransitionReason extends Model
{
    protected $table = 'subscription_transition_reason';

    protected $primaryKey = 'reason_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}
