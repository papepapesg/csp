<?php

namespace Modules\Catalog\Plm\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-04 §6.5 allowed bundle-to-bundle movement rule. */
class BundleMigrationRule extends Model
{
    use HasPrefixedId;

    protected $table = 'commercial_bundle_migration_rule';

    protected $primaryKey = 'migration_rule_id';

    protected string $idPrefix = 'bmr';

    protected $guarded = [];

    protected $casts = ['allowed_channel_json' => 'array', 'requires_customer_consent' => 'boolean', 'requires_wo' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];

    public $timestamps = true;
}
