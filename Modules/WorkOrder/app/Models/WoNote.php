<?php

namespace Modules\WorkOrder\Models;

use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/** WO-01 §1.3 structured note — a typed, append-only entry on a work order. */
class WoNote extends Model
{
    protected $table = 'wo_note';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->id ??= Id::make('wonote'));
    }
}
