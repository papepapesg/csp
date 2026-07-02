<?php

namespace Modules\Billing\Mediation\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-CFG-01 BillableEvent — one chargeable-event template in the operator's
 * catalog. Declares how it fires (trigger taxonomy), who it applies to
 * (applicability + eligibility filters), and its signed-amount semantics.
 *
 * @property string $code
 * @property string $status
 */
class BillableEvent extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    public const TRIGGER_TYPES = ['SAGA_INTENT', 'LIFECYCLE_EVENT', 'ADMIN_ACTION', 'CUSTOMER_PURCHASE', 'EXTERNAL_PAYMENT', 'SCHEDULED'];

    // Signed-amount semantics (R-AS-*).
    public const POSITIVE_ONLY = 'POSITIVE_ONLY';

    public const NEGATIVE_ONLY = 'NEGATIVE_ONLY';

    public const SIGNED = 'SIGNED';

    public const SIGN_POLICIES = [self::POSITIVE_ONLY, self::NEGATIVE_ONLY, self::SIGNED];

    public const APPLICABILITIES = ['PREPAID_ONLY', 'POSTPAID_ONLY', 'ANY'];

    protected $table = 'billable_event';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'bev';

    protected $guarded = [];

    protected $casts = [
        'service_refs' => 'array',
        'state_callback' => 'array',
        'eligibility_franchise_refs' => 'array',
        'eligibility_package_refs' => 'array',
        'eligibility_segment_refs' => 'array',
        'pay_first_required' => 'boolean',
        'retired_at' => 'datetime',
    ];

    /** R-BIL-CFG-01-B-5: does this event apply to the given billing mode? */
    public function appliesToBillingMode(string $billingMode): bool
    {
        return match ($this->applicability) {
            'PREPAID_ONLY' => $billingMode === 'PREPAID',
            'POSTPAID_ONLY' => $billingMode === 'POSTPAID',
            default => true,
        };
    }
}
