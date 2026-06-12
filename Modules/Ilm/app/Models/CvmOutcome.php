<?php

namespace Modules\Ilm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-03 final outcome of an activity/offer. */
class CvmOutcome extends Model
{
    use HasPrefixedId;

    protected $table = 'cvm_outcome';
    protected $primaryKey = 'outcome_id';
    protected string $idPrefix = 'cvoo';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
