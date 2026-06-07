<?php

namespace Modules\PaymentGateway\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * PAY-GW-01 inbound callback record.
 *
 * @property string $callback_id
 * @property string $status
 */
class PaymentGatewayCallback extends Model
{
    use HasPrefixedId;

    public const RECEIVED = 'RECEIVED';

    public const PROCESSED = 'PROCESSED';

    public const REJECTED = 'REJECTED';

    public const DUPLICATE = 'DUPLICATE';

    protected $table = 'payment_gateway_callback';

    protected $primaryKey = 'callback_id';

    protected string $idPrefix = 'pgcb';

    protected $guarded = [];

    protected $casts = ['raw' => 'array', 'amount' => 'decimal:2', 'received_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'callback_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
