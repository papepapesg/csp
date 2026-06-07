<?php

namespace Modules\Ilm\Models;

use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/**
 * Free-text customer note (ILM-CFG-01). Distinct from structured interactions.
 *
 * @property string $id
 */
class CustomerNote extends Model
{
    protected $table = 'customer_note';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (CustomerNote $n) => $n->id ??= Id::make('note'));
    }
}
