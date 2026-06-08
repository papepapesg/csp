<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/** SUB-LM-01 §5.2 lifecycle status catalog. */
class SubscriptionStatusCode extends Model
{
    protected $table = 'subscription_status_code';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean', 'is_billable' => 'boolean', 'is_terminal' => 'boolean',
        'is_pending' => 'boolean', 'allows_package_change' => 'boolean', 'allows_address_move' => 'boolean', 'active' => 'boolean',
    ];
}
