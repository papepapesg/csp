<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 per-operator channel configuration (R-NOT-01-C-2). Selects which adapter
 * implementation a (operator, channel) uses, the sender identity, a reference to the
 * credentials in the secret store, and free-form adapter config. The pluggable
 * adapter_implementation is the extension seam: an operator swaps SMS gateways by
 * changing this row, never code.
 *
 * @property string $id
 * @property bool $enabled
 */
class ChannelOperatorConfig extends Model
{
    use HasPrefixedId;

    protected $table = 'channel_operator_config';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'coc';

    protected $guarded = [];

    protected $casts = [
        'additional_config' => 'array',
        'enabled' => 'bool',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public static function resolve(string $operator, string $channel): ?self
    {
        return static::query()->where('operator_code', $operator)->where('channel', $channel)->first();
    }
}
