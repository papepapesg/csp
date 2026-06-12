<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-02 §5.5 package lifecycle event — an immutable audit row written on every
 * status change (validation, approval, activation, suspension, resume, end-of-sale,
 * end-of-life, retirement). The table has no updated_at; rows are never mutated.
 */
class PackageLifecycleEvent extends Model
{
    use HasPrefixedId;

    public const UPDATED_AT = null;

    protected $table = 'package_lifecycle_event';

    protected $primaryKey = 'event_id';

    protected string $idPrefix = 'ple';

    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function getRouteKeyName(): string
    {
        return 'event_id';
    }
}
