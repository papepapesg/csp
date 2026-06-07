<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PLM config catalog — adjustment_type. */
class AdjustmentType extends Model
{
    use HasPrefixedId;

    protected $table = 'adjustment_type';

    protected $primaryKey = 'adjustment_type_id';

    protected string $idPrefix = 'atyp';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
