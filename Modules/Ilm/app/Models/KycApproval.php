<?php

namespace Modules\Ilm\Models;

use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/**
 * KYC approval-chain row (ILM-CFG-01 §KYC). customer.kyc_status is derived from
 * these decisions.
 *
 * @property string $id
 */
class KycApproval extends Model
{
    protected $table = 'customer_kyc_approval';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_final' => 'boolean',
        'approval_level' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (KycApproval $a) => $a->id ??= Id::make('kyc'));
    }
}
