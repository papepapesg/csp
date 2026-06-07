<?php

namespace Modules\Ilm\Models;

use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/**
 * Structured interaction history entry (ILM-CFG-01 §1).
 *
 * @property string $id
 */
class CustomerInteraction extends Model
{
    protected $table = 'customer_interaction';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (CustomerInteraction $i) => $i->id ??= Id::make('int'));
    }
}
