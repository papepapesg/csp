<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** BIL-02 invoice line — a SUMMARY roll-up or a DETAIL leaf under it (BIL-02-GEN-01). */
class InvoiceLine extends Model
{
    use HasPrefixedId;

    public const SUMMARY = 'SUMMARY';

    public const DETAIL = 'DETAIL';

    protected $table = 'invoice_line';

    protected string $idPrefix = 'invl';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_breakdown' => 'array',
    ];

    /** DETAIL lines nested under this SUMMARY line. */
    public function details(): HasMany
    {
        return $this->hasMany(self::class, 'parent_line_id', 'id');
    }
}
