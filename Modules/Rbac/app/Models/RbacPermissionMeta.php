<?php

namespace Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Model;

/** EM-CFG-03 permission catalog metadata (module/risk/scope_required) over Spatie permissions (DD §8.2). */
class RbacPermissionMeta extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'rbac_permission_meta';
    protected $primaryKey = 'permission_code';
    protected $guarded = [];
    protected $casts = ['scope_required' => 'bool'];
}
