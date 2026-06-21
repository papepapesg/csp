<?php

namespace App\Foundation\Approvals;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-CFG-04 one ordered stage of an approval chain (targets a ROLE or a named USER). */
class ApprovalStage extends Model
{
    use HasPrefixedId;

    public const ROLE = 'ROLE';

    public const USER = 'USER';

    protected $table = 'approval_stage';

    protected $primaryKey = 'stage_id';

    protected string $idPrefix = 'appds';

    protected $guarded = [];

    protected $casts = ['approver_roles' => 'array', 'allow_requester' => 'boolean', 'sequence' => 'integer', 'required_approvals' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
