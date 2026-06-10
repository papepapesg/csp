<?php

namespace Modules\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;

/** TCK-01 §7.6 ticket category catalog — routing defaults + WO-allowed gating. */
class TicketCategory extends Model
{
    protected $table = 'ticket_category_catalog';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['wo_allowed' => 'boolean', 'active' => 'boolean', 'review_required' => 'boolean'];

    public static function resolve(string $operator, ?string $category): ?self
    {
        if (! $category) {
            return null;
        }

        return static::query()->where('operator_code', $operator)->where('category_code', $category)->first();
    }
}
