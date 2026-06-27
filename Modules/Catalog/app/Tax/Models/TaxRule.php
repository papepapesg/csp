<?php

namespace Modules\Catalog\Tax\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** PLM-CFG-02 tax rule (one taxable category's tax). */
class TaxRule extends Model
{
    use HasPrefixedId;

    protected $table = 'tax_rule';

    protected $primaryKey = 'tax_rule_id';

    protected string $idPrefix = 'txr';

    protected $guarded = [];

    protected $casts = [
        'rate' => 'decimal:4',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];
}
