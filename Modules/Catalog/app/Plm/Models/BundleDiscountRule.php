<?php

namespace Modules\Catalog\Plm\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-04 §6.4 discount assignment rule attached to a bundle. */
class BundleDiscountRule extends Model
{
    use HasPrefixedId;

    protected $table = 'commercial_bundle_discount_rule';

    protected $primaryKey = 'discount_rule_id';

    protected string $idPrefix = 'bdr';

    protected $guarded = [];

    public $timestamps = true;
}
