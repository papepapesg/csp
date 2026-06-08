<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/** SUB-WF-FRAMEWORK-01 §6.3 per-operator/per-kind operation orchestration config. */
class SubscriptionOperationConfig extends Model
{
    protected $table = 'subscription_operation_config';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['feature_flags' => 'array', 'enabled' => 'boolean'];

    /** Resolve the process key + config for an (operator, kind), or null. */
    public static function resolve(string $operator, string $kind): ?self
    {
        return static::query()->where('operator_code', $operator)->where('operation_kind', $kind)->first();
    }
}
