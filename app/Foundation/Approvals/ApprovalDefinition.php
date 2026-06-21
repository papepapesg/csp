<?php

namespace App\Foundation\Approvals;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** EM-CFG-04 approval policy row. */
class ApprovalDefinition extends Model
{
    use HasPrefixedId;

    protected $table = 'approval_definition';

    protected $primaryKey = 'definition_id';

    protected string $idPrefix = 'appd';

    protected $guarded = [];

    protected $casts = ['approver_roles' => 'array', 'active' => 'boolean', 'threshold_amount' => 'decimal:2'];

    /** Ordered chain of stages (empty ⇒ legacy single implicit stage from the flat columns). */
    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalStage::class, 'definition_id', 'definition_id')->orderBy('sequence');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
