<?php

namespace Modules\Ilm\Models;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Ilm\Database\Factories\CustomerFactory;

/**
 * Customer — tier 1 of the CAS hierarchy (ILM-CFG-01 §2.1).
 *
 * Holds only legal-identity attributes. Operational state lives on the Account.
 *
 * @property string $customer_id
 * @property string $kyc_status
 */
class Customer extends Model
{
    use HasFactory;
    use \App\Foundation\Tenancy\BelongsToOperator;

    public const KYC_PENDING = 'PENDING';

    public const KYC_L1_APPROVED = 'L1_APPROVED';

    public const KYC_APPROVED = 'APPROVED';

    public const KYC_REJECTED = 'REJECTED';

    protected $table = 'customer';

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'business_reg_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            $customer->customer_id ??= Id::customer();
            $customer->operator_code ??= Context::operatorCode();
            $customer->kyc_status ??= self::KYC_PENDING;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'customer_id';
    }

    protected static function newFactory(): Factory
    {
        return CustomerFactory::new();
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(CustomerAccount::class, 'customer_id', 'customer_id');
    }

    public function contactMethods(): HasMany
    {
        return $this->hasMany(ContactMethod::class, 'customer_id', 'customer_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class, 'customer_id', 'customer_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(CustomerInteraction::class, 'customer_id', 'customer_id');
    }

    public function kycApprovals(): HasMany
    {
        return $this->hasMany(KycApproval::class, 'customer_id', 'customer_id');
    }
}
