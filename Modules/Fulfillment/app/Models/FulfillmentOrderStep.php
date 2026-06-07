<?php

namespace Modules\Fulfillment\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** FUL-02 order step record. */
class FulfillmentOrderStep extends Model
{
    use HasPrefixedId;

    protected $table = 'fulfillment_order_step';

    protected string $idPrefix = 'fost';

    protected $guarded = [];

    protected $casts = ['result' => 'array', 'completed_at' => 'datetime'];
}
