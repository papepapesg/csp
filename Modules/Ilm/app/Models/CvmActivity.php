<?php

namespace Modules\Ilm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * EM-03 CVM activity — an outreach/retention/recovery/upsell/win-back task (DD §4.3).
 * Lifecycle: OPEN -> IN_PROGRESS -> WAITING_CUSTOMER -> COMPLETED / CANCELLED / EXPIRED.
 * Distinct from cvm_offer_instance: an activity is the task, an offer is what's proposed.
 */
class CvmActivity extends Model
{
    use HasPrefixedId;

    public const OPEN = 'OPEN';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const WAITING_CUSTOMER = 'WAITING_CUSTOMER';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const EXPIRED = 'EXPIRED';

    protected $table = 'cvm_activity';

    protected $primaryKey = 'activity_id';

    protected string $idPrefix = 'cva';

    protected $guarded = [];

    protected $casts = ['offer_details' => 'array', 'expires_at' => 'datetime', 'decided_at' => 'datetime', 'due_at' => 'datetime', 'closed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'activity_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function offers()
    {
        return $this->hasMany(CvmOfferInstance::class, 'activity_id', 'activity_id');
    }
}
