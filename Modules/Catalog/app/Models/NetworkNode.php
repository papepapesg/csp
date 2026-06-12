<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** RLM-CFG-01 network node catalog — the controlled list a HomePass network_path references. */
class NetworkNode extends Model
{
    use HasPrefixedId;

    public const TYPES = ['OLT', 'SPLITTER', 'FAT', 'FDT', 'ONT', 'HEADEND', 'DISTRIBUTION_NODE', 'AMPLIFIER', 'LINE_EXTENDER', 'VOIPSWITCH', 'NMS', 'OTHER'];

    protected $table = 'network_node';
    protected $primaryKey = 'node_id';
    protected string $idPrefix = 'nnode';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'node_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
