<?php

namespace Modules\Subscription\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * SUB-LM-01 restriction catalog row. Defines a restriction code, the opaque
 * fulfillment_action FUL-04 translates to vendor commands, and the self-service /
 * admin-only / active flags the RESTRICT validation snapshots (R-C-2).
 *
 * @property string $restriction_code
 * @property string $fulfillment_action
 * @property bool $customer_self_service_eligible
 * @property bool $admin_only
 * @property bool $is_active
 */
class SubscriptionRestriction extends Model
{
    use HasPrefixedId;

    protected $table = 'subscription_restriction';

    protected $primaryKey = 'restriction_id';

    protected string $idPrefix = 'srest';

    protected $guarded = [];

    protected $casts = [
        'customer_self_service_eligible' => 'boolean',
        'admin_only' => 'boolean',
        'is_active' => 'boolean',
    ];
}
