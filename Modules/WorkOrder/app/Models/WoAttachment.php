<?php

namespace Modules\WorkOrder\Models;
use Modules\WorkOrder\Models\WorkOrder;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/** WO-01 §1.4 categorised attachment on a work order. */
class WoAttachment extends Model
{
    protected $table = 'wo_attachment';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->id ??= Id::make('woatt');
            $m->operator_code ??= Context::operatorCode();
        });
    }
}
