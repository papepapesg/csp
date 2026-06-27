<?php

namespace Modules\Catalog\Plm\Models;
use Modules\Catalog\Plm\Models\PackageAvailability;
use Modules\Catalog\Plm\Models\PackageLaunchCheck;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SIP-02 §5.1 package launch plan — the launch request for one package version
 * and the lifecycle state machine (DD §3-4): DRAFT → READY_FOR_REVIEW →
 * PENDING_APPROVAL / APPROVED → SCHEDULED / ACTIVE → SUSPENDED / END_OF_SALE →
 * END_OF_LIFE → RETIRED.
 */
class PackageLaunchPlan extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_END_OF_SALE = 'END_OF_SALE';

    public const STATUS_END_OF_LIFE = 'END_OF_LIFE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'package_launch_plan';

    protected $primaryKey = 'launch_plan_id';

    protected string $idPrefix = 'plp';

    protected $guarded = [];

    protected $casts = [
        'requested_launch_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function getRouteKeyName(): string
    {
        return 'launch_plan_id';
    }

    public function checks(): HasMany
    {
        return $this->hasMany(PackageLaunchCheck::class, 'launch_plan_id', 'launch_plan_id');
    }

    /** The availability rows for this plan's package version (this operator). */
    public function availabilityRows(): \Illuminate\Database\Eloquent\Builder
    {
        return PackageAvailability::query()
            ->where('operator_code', $this->operator_code)
            ->where('package_id', $this->package_id)
            ->where('package_version_id', $this->package_version_id);
    }
}
