<?php

namespace App\Foundation\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-operator deployment configuration (identity, locale, currency, theme, logs). */
class OperatorConfig extends Model
{
    protected $table = 'operator_config';

    protected $primaryKey = 'operator_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['extras' => 'array'];

    public static function forOperator(?string $operator): ?self
    {
        return $operator ? static::query()->find($operator) : null;
    }
}
