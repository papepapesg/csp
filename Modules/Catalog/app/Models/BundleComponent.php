<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-04 §6.2 package component of a bundle. */
class BundleComponent extends Model
{
    use HasPrefixedId;

    protected $table = 'commercial_bundle_component';

    protected $primaryKey = 'component_id';

    protected string $idPrefix = 'bcomp';

    protected $guarded = [];

    protected $casts = ['metadata_json' => 'array', 'mandatory' => 'boolean'];

    public $timestamps = true;
}
