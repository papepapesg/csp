<?php

namespace Modules\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

/** EM-02 §3.3 operator-extensible skill catalog. */
class SkillCatalog extends Model
{
    protected $table = 'skill_catalog';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}
