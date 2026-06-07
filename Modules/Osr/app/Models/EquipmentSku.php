<?php

namespace Modules\Osr\Models;

use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PLM-CFG-06 equipment SKU/type. operator-scoped id, e.g. WIK-ONT-HUAWEI. */
class EquipmentSku extends Model
{
    protected $table = 'equipment_sku';

    protected $primaryKey = 'sku_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['is_serialized' => 'boolean', 'active' => 'boolean', 'deposit_amount' => 'decimal:2'];

    public function getRouteKeyName(): string
    {
        return 'sku_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
