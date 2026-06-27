<?php

namespace Modules\Catalog\Plm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-02 §5.4 package version cutover — records the current-version change from one
 * package version to another (price/geo/tax/wallet). R-SIP-02-08: the cutover moves
 * NEW sales to the new version; existing subscriptions stay on their referenced one.
 */
class PackageVersionCutover extends Model
{
    use HasPrefixedId;

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'package_version_cutover';

    protected $primaryKey = 'cutover_id';

    protected string $idPrefix = 'pvc';

    protected $guarded = [];

    protected $casts = [
        'cutover_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function getRouteKeyName(): string
    {
        return 'cutover_id';
    }
}
