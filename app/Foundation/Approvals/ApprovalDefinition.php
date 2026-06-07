<?php

namespace App\Foundation\Approvals;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-CFG-04 approval policy row. */
class ApprovalDefinition extends Model
{
    use HasPrefixedId;

    protected $table = 'approval_definition';

    protected $primaryKey = 'definition_id';

    protected string $idPrefix = 'appd';

    protected $guarded = [];

    protected $casts = ['approver_roles' => 'array', 'active' => 'boolean', 'threshold_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
