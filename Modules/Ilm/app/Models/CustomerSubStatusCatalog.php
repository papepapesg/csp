<?php

namespace Modules\Ilm\Models;

use Illuminate\Database\Eloquent\Model;

/** ILM-CFG-01 operator sub-status registry. */
class CustomerSubStatusCatalog extends Model
{
    protected $table = 'customer_sub_status_catalog';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public static function exists(string $operator, string $subStatus): bool
    {
        return static::query()->where('operator_code', $operator)->where('sub_status_code', $subStatus)->exists();
    }
}
