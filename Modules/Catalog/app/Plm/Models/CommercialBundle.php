<?php

namespace Modules\Catalog\Plm\Models;
use Modules\Catalog\Plm\Models\BundleAvailability;
use Modules\Catalog\Plm\Models\BundleComponent;
use Modules\Catalog\Plm\Models\BundleDiscountRule;
use Modules\Catalog\Plm\Models\BundleLaunchCheck;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SIP-04 commercial bundle — a launchable offer composed of SIP-01 packages, with
 * availability gating, optional discount rules and controlled migration paths.
 * Lifecycle (§5): DRAFT → READY_FOR_REVIEW → APPROVED → ACTIVE → SUSPENDED/RETIRED.
 */
class CommercialBundle extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    public const APPROVED = 'APPROVED';

    public const ACTIVE = 'ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    public const RETIRED = 'RETIRED';

    public const REJECTED = 'REJECTED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'commercial_bundle';

    protected $primaryKey = 'bundle_id';

    protected string $idPrefix = 'bun';

    protected $guarded = [];

    protected $casts = ['launch_date' => 'date', 'retire_date' => 'date'];

    public function getRouteKeyName(): string
    {
        return 'bundle_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function components(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'bundle_id', 'bundle_id')->orderBy('display_order');
    }

    public function availability(): HasMany
    {
        return $this->hasMany(BundleAvailability::class, 'bundle_id', 'bundle_id');
    }

    public function discountRules(): HasMany
    {
        return $this->hasMany(BundleDiscountRule::class, 'bundle_id', 'bundle_id');
    }

    public function launchChecks(): HasMany
    {
        return $this->hasMany(BundleLaunchCheck::class, 'bundle_id', 'bundle_id');
    }
}
