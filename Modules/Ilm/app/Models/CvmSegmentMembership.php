<?php

namespace Modules\Ilm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-03 current CVM segment placement (Drools-driven targeting). */
class CvmSegmentMembership extends Model
{
    use HasPrefixedId;

    public const ACTIVE = 'ACTIVE';
    public const EXPIRED = 'EXPIRED';
    public const SUPPRESSED = 'SUPPRESSED';

    protected $table = 'cvm_segment_membership';
    protected $primaryKey = 'membership_id';
    protected string $idPrefix = 'csm';
    protected $guarded = [];
    protected $casts = ['entered_at' => 'datetime', 'expires_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
