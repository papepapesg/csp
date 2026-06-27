<?php

namespace Modules\Catalog\Plm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-01 service class — commercial grouping of services.
 */
class ServiceClass extends Model
{
    use HasPrefixedId;

    protected $table = 'service_class';

    protected string $idPrefix = 'scls';

    protected $guarded = [];

    protected $casts = ['requires_equipment' => 'boolean', 'retired_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
