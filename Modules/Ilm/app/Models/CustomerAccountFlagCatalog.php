<?php

namespace Modules\Ilm\Models;

use Illuminate\Database\Eloquent\Model;

/** ILM-CFG-01 §3.5 operator-extensible account flag catalog. */
class CustomerAccountFlagCatalog extends Model
{
    protected $table = 'customer_account_flag_catalog';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['surfaces_attention' => 'boolean', 'active' => 'boolean'];

    public static function resolve(string $operator, string $flagCode): ?self
    {
        return static::query()->where('operator_code', $operator)->where('flag_code', $flagCode)->first();
    }
}
