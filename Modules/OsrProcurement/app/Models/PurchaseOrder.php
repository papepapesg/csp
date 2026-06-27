<?php

namespace Modules\Osr\Procurement\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** OSR-02 / OSR-05 — purchase_order. */
class PurchaseOrder extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $table = 'purchase_order';

    protected $primaryKey = 'po_id';

    protected string $idPrefix = 'po';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'po_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
