<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * SALES-01 commercial lead. Lifecycle (DD §7):
 *   DRAFT → NEW → ASSIGNED → CONTACTED/FOLLOW_UP → QUALIFIED → CONVERTED / LOST / DUPLICATE / CANCELLED.
 */
class SalesLead extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';
    public const NEW = 'NEW';
    public const ASSIGNED = 'ASSIGNED';
    public const CONTACTED = 'CONTACTED';
    public const FOLLOW_UP = 'FOLLOW_UP';
    public const QUALIFIED = 'QUALIFIED';
    public const CONVERTED = 'CONVERTED';
    public const LOST = 'LOST';
    public const DUPLICATE = 'DUPLICATE';
    public const CANCELLED = 'CANCELLED';

    /** Allowed lead state transitions (DD §7). */
    public const TRANSITIONS = [
        'DRAFT' => ['NEW', 'CANCELLED'],
        'NEW' => ['ASSIGNED', 'DUPLICATE', 'CANCELLED', 'QUALIFIED'],
        'ASSIGNED' => ['CONTACTED', 'UNREACHABLE', 'CANCELLED', 'QUALIFIED'],
        'CONTACTED' => ['QUALIFIED', 'NOT_INTERESTED', 'FOLLOW_UP', 'LOST'],
        'FOLLOW_UP' => ['CONTACTED', 'QUALIFIED', 'NOT_INTERESTED', 'LOST'],
        'QUALIFIED' => ['CONVERTED', 'LOST', 'DUPLICATE'],
    ];

    protected $table = 'sales_lead';

    protected $primaryKey = 'lead_id';

    protected string $idPrefix = 'lead';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['consent_captured' => 'bool', 'geo_lat' => 'decimal:6', 'geo_lng' => 'decimal:6'];

    public function getRouteKeyName(): string
    {
        return 'lead_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
