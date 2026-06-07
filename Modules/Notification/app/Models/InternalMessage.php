<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** ICN-01 internal communication / task. */
class InternalMessage extends Model
{
    use HasPrefixedId;

    protected $table = 'internal_message';

    protected $primaryKey = 'message_id';

    protected string $idPrefix = 'icn';

    protected $guarded = [];

    protected $casts = ['read_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'message_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
