<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-02 §5.6 package retirement plan — a controlled end-of-sale / end-of-life plan.
 * R-SIP-02-09: END_OF_SALE blocks new sales but never terminates existing
 * subscriptions. R-SIP-02-10: a MIGRATE_REQUIRED policy must reference a SUB/FUL
 * migration workflow.
 */
class PackageRetirementPlan extends Model
{
    use HasPrefixedId;

    public const TYPE_END_OF_SALE = 'END_OF_SALE';

    public const TYPE_END_OF_LIFE = 'END_OF_LIFE';

    public const TYPE_RETIRE_VERSION = 'RETIRE_VERSION';

    public const POLICY_KEEP_AS_IS = 'KEEP_AS_IS';

    public const POLICY_MIGRATE_REQUIRED = 'MIGRATE_REQUIRED';

    public const POLICY_BLOCK_RENEWAL = 'BLOCK_RENEWAL';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'package_retirement_plan';

    protected $primaryKey = 'retirement_plan_id';

    protected string $idPrefix = 'prp';

    protected $guarded = [];

    protected $casts = [
        'effective_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function getRouteKeyName(): string
    {
        return 'retirement_plan_id';
    }
}
