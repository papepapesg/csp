<?php

namespace Modules\Rbac\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** EM-CFG-03 frontend menu/action catalog (DD §8.6). UI visibility — never security. */
class RbacFrontendAction extends Model
{
    use HasPrefixedId;

    protected $table = 'rbac_frontend_action';
    protected $primaryKey = 'frontend_action_id';
    protected string $idPrefix = 'fea';
    protected $guarded = [];
}
