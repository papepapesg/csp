<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** RLM-CFG-01 contractor company/team; carries its skill set. */
class TechContractor extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'tech_contractor';
    protected $primaryKey = 'contractor_id';
    protected string $idPrefix = 'tcon';
    protected $guarded = [];
    protected $casts = ['skills' => 'array', 'has_been_active' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'contractor_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
