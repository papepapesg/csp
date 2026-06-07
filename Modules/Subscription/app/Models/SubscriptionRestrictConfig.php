<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SUB-WF-RESTRICT-01 per-operator config: whether customers may self-serve
 * restrictions, whether dunning-marked restrictions are protected from human
 * removal, and which codes require an approval task before commit.
 *
 * @property bool $customer_self_service_enabled
 * @property bool $dunning_marker_strict
 * @property array $restriction_approval_required_for_codes
 */
class SubscriptionRestrictConfig extends Model
{
    protected $table = 'subscription_restrict_config';

    protected $primaryKey = 'operator_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'customer_self_service_enabled' => 'boolean',
        'dunning_marker_strict' => 'boolean',
        'restriction_approval_required_for_codes' => 'array',
    ];

    /** Operator config with safe defaults when no row is seeded yet. */
    public static function forOperator(string $operator): self
    {
        return static::query()->find($operator) ?? new self([
            'operator_code' => $operator,
            'customer_self_service_enabled' => false,
            'dunning_marker_strict' => true,
            'restriction_approval_required_for_codes' => [],
        ]);
    }
}
