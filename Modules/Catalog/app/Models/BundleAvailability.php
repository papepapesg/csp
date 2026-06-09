<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-04 §6.3 channel/region/franchise availability row. */
class BundleAvailability extends Model
{
    use HasPrefixedId;

    protected $table = 'commercial_bundle_availability';

    protected $primaryKey = 'availability_id';

    protected string $idPrefix = 'bav';

    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    public $timestamps = true;
}
