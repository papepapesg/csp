<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-02 §5.3 package availability — where and through which channels a package
 * version is sellable. The sellable read model (GET /api/packages/available) is
 * built from ACTIVE rows of this table joined to the package + active version.
 */
class PackageAvailability extends Model
{
    use HasPrefixedId;

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_ENDED = 'ENDED';

    protected $table = 'package_availability';

    protected $primaryKey = 'availability_id';

    protected string $idPrefix = 'pav';

    protected $guarded = [];

    protected $casts = [
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function getRouteKeyName(): string
    {
        return 'availability_id';
    }
}
