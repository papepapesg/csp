<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-01 package version — priced, time-bounded snapshot of a package.
 */
class PackageVersion extends Model
{
    use HasPrefixedId;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $table = 'package_version';

    protected string $idPrefix = 'pkv';

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'target_franchises' => 'array',
        'target_tech_regions' => 'array',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];
}
