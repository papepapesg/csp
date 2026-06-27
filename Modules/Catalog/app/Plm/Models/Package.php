<?php

namespace Modules\Catalog\Plm\Models;
use Modules\Catalog\Plm\Models\PackageService;
use Modules\Catalog\Plm\Models\PackageVersion;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SIP-01 package — a commercial offer composed of services, priced via versions.
 */
class Package extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_END_OF_LIFE = 'END_OF_LIFE';

    protected $table = 'package';

    protected string $idPrefix = 'pkg';

    protected $guarded = [];

    protected $casts = [
        'target_franchises' => 'array',
        'target_tech_regions' => 'array',
        'billing_frequency_days' => 'integer',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class, 'package_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(PackageService::class, 'package_id');
    }
}
