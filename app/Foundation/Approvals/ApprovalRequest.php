<?php

namespace App\Foundation\Approvals;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-CFG-04 approval request lifecycle row. */
class ApprovalRequest extends Model
{
    use HasPrefixedId;

    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const AUTO_APPROVED = 'AUTO_APPROVED';

    protected $table = 'approval_request';

    protected $primaryKey = 'request_id';

    protected string $idPrefix = 'appr';

    protected $guarded = [];

    protected $casts = ['approver_roles' => 'array', 'payload' => 'array', 'amount' => 'decimal:2', 'allow_requester' => 'boolean', 'decided_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'request_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
