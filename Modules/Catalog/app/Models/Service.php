<?php

namespace Modules\Catalog\Models;
use Modules\Catalog\Models\ServiceClass;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PLM-CFG-01 service — the sellable/provisionable unit.
 */
class Service extends Model
{
    use HasPrefixedId;

    protected $table = 'service';

    protected string $idPrefix = 'svc';

    protected $guarded = [];

    protected $casts = [
        'is_addressable' => 'boolean',
        'network_profile_shape' => 'array',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function serviceClass(): BelongsTo
    {
        return $this->belongsTo(ServiceClass::class, 'service_class_id');
    }
}
