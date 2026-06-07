<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PROV-INT-01 provisioning target (external NMS/platform). */
class ProvisioningTarget extends Model
{
    protected $table = 'provisioning_target';

    protected $primaryKey = 'target_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
