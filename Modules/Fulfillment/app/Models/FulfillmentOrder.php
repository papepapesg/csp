<?php

namespace Modules\Fulfillment\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FUL-02 onboarding/order-capture aggregate.
 *
 * @property string $order_id
 * @property string $status
 */
class FulfillmentOrder extends Model
{
    use HasPrefixedId;

    public const CAPTURED = 'CAPTURED';

    public const AWAITING_INSTALL = 'AWAITING_INSTALL';

    public const AWAITING_KYC = 'AWAITING_KYC';

    public const ACTIVATING = 'ACTIVATING';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'fulfillment_order';

    protected $primaryKey = 'order_id';

    protected string $idPrefix = 'ford';

    protected $guarded = [];

    protected $casts = ['completed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'order_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function steps(): HasMany
    {
        return $this->hasMany(FulfillmentOrderStep::class, 'order_id', 'order_id');
    }
}
