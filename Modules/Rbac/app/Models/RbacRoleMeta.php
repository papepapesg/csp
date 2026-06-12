<?php

namespace Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Model;

/** EM-CFG-03 role catalog metadata (family/display/status) layered over Spatie roles (DD §8.1). */
class RbacRoleMeta extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'rbac_role_meta';
    protected $primaryKey = 'role_code';
    protected $guarded = [];
}
