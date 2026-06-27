<?php

namespace Modules\Catalog\Tax\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** PLM-CFG-02 tax group (ordered set of tax rules). */
class TaxGroup extends Model
{
    use HasPrefixedId;

    protected $table = 'tax_group';

    protected $primaryKey = 'tax_group_id';

    protected string $idPrefix = 'txg';

    protected $guarded = [];

    protected $casts = ['order_within_group' => 'array'];
}
