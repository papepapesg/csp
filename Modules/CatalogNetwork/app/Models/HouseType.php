<?php

namespace Modules\Catalog\Network\Models;

use Illuminate\Database\Eloquent\Model;

/** RLM-CFG-01 building-density classification catalog. */
class HouseType extends Model
{
    protected $table = 'house_type';
    public $incrementing = false;
    protected $primaryKey = null;
    protected $keyType = 'string';
    protected $guarded = [];
}
