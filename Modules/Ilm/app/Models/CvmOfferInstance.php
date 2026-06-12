<?php

namespace Modules\Ilm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-03 one offer proposed to a customer through CVM (with EM-CFG-04 approval ref). */
class CvmOfferInstance extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';
    public const PROPOSED = 'PROPOSED';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';
    public const EXPIRED = 'EXPIRED';
    public const APPLIED = 'APPLIED';
    public const FAILED = 'FAILED';
    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    protected $table = 'cvm_offer_instance';
    protected $primaryKey = 'offer_instance_id';
    protected string $idPrefix = 'cvo';
    protected $guarded = [];
    protected $casts = ['discount_percent' => 'decimal:2', 'expires_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'offer_instance_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
