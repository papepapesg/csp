<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * RLM-CFG-01 HomePass — a serviceable physical premise/address. Reference data
 * consumed by subscription, fulfillment and work orders (HLD §4).
 */
class HomePass extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SERVICEABLE = 'SERVICEABLE';

    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'homepass';

    protected string $idPrefix = 'hp';

    protected $guarded = [];

    protected $casts = [
        'has_been_active' => 'boolean',
        'network_nodes' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
