<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PLM config catalog — equipment_type. */
class EquipmentType extends Model
{
    use HasPrefixedId;

    protected $table = 'equipment_type';

    protected $primaryKey = 'equipment_type_id';

    protected string $idPrefix = 'etyp';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
