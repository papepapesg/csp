<?php

namespace Modules\Catalog\Models;
use Modules\Catalog\Models\HomePass;

use Illuminate\Database\Eloquent\Model;

/** RLM-CFG-01 §1 per-deployment HomePass status code with semantic flags. */
class HomePassStatusCode extends Model
{
    protected $table = 'homepass_status_code';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_initial' => 'boolean', 'is_sellable' => 'boolean', 'is_active' => 'boolean',
        'is_terminal' => 'boolean', 'requires_approval_to_enter' => 'boolean',
        'triggers_lead_notification' => 'boolean', 'blocks_soft_delete' => 'boolean', 'active' => 'boolean',
    ];

    public static function resolve(string $operator, string $code): ?self
    {
        return static::query()->where('operator_code', $operator)->where('code', $code)->first();
    }
}
