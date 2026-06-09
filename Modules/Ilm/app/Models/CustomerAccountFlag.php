<?php

namespace Modules\Ilm\Models;

use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/** ILM-CFG-01 §3.5 an account's current state for one flag. */
class CustomerAccountFlag extends Model
{
    public const ACTIVE = 'ACTIVE';

    public const CLEARED = 'CLEARED';

    protected $table = 'customer_account_flag';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['bool_value' => 'boolean', 'score_value' => 'integer', 'set_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->id ??= Id::make('acflg'));
    }
}
