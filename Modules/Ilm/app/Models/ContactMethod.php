<?php

namespace Modules\Ilm\Models;

use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer contact method / channel (ILM-CFG-01).
 *
 * @property string $id
 */
class ContactMethod extends Model
{
    protected $table = 'customer_contact_method';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (ContactMethod $m) => $m->id ??= Id::make('cm'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }
}
