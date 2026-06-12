<?php

namespace Modules\Rbac\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-CFG-03 user scope assignment — where a user's permissions apply (DD §8.5). */
class RbacUserScope extends Model
{
    use HasPrefixedId;

    public const OPERATOR = 'OPERATOR';
    public const FRANCHISE = 'FRANCHISE';
    public const TECH_REGION = 'TECH_REGION';
    public const CONTRACTOR = 'CONTRACTOR';
    public const TEAM = 'TEAM';
    public const CHANNEL = 'CHANNEL';
    public const GLOBAL = 'GLOBAL';

    protected $table = 'rbac_user_scope_assignment';
    protected $primaryKey = 'scope_assignment_id';
    protected string $idPrefix = 'usa';
    protected $guarded = [];
    protected $casts = ['active' => 'bool', 'effective_from' => 'datetime', 'effective_to' => 'datetime'];

    public function getRouteKeyName(): string { return 'scope_assignment_id'; }
    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }
}
