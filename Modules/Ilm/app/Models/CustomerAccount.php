<?php

namespace Modules\Ilm\Models;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer Account — tier 2 of the CAS hierarchy (ILM-CFG-01 §2.2).
 *
 * One row per physical service deployment ("1 account = 1 deployment"). Carries
 * operational status/sub-status, service class, the 1:1 subscription link and
 * the operational account_number used by every other module.
 *
 * @property string $account_id
 * @property string $status
 * @property string $sub_status
 */
class CustomerAccount extends Model
{
    use \App\Foundation\Tenancy\BelongsToOperator;

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_ACTIVE = 'ACTIVE';

    protected $table = 'customer_account';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'sub_status_changed_at' => 'datetime',
        'start_bill_date' => 'date',
        'install_date' => 'date',
        'disconnect_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (CustomerAccount $account) {
            $account->account_id ??= Id::account();
            $account->operator_code ??= Context::operatorCode();
            $account->account_number ??= self::generateAccountNumber();
            $account->status ??= self::STATUS_INACTIVE;
            $account->sub_status ??= 'NEW';
        });
    }

    public function getRouteKeyName(): string
    {
        return 'account_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /** Workshop format e.g. 002-0598570T (simplified, operator-unique). */
    private static function generateAccountNumber(): string
    {
        return '002-'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT).'T';
    }
}
