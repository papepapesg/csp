<?php

namespace Modules\Ilm\Cvm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-03 aggregated customer churn/upsell signals + scores (one row per customer). */
class CvmSignalProfile extends Model
{
    use HasPrefixedId;

    protected $table = 'cvm_customer_signal_profile';
    protected $primaryKey = 'signal_profile_id';
    protected string $idPrefix = 'csp';
    protected $guarded = [];
    protected $casts = [
        'active_subscription_count' => 'integer', 'open_ticket_count' => 'integer', 'dunning_level' => 'integer',
        'days_since_last_payment' => 'integer', 'complaint_count_90d' => 'integer',
        'churn_risk_score' => 'decimal:2', 'upsell_score' => 'decimal:2', 'last_evaluated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public static function forCustomer(string $operator, string $customerId): ?self
    {
        return static::query()->where('operator_code', $operator)->where('customer_id', $customerId)->first();
    }
}
