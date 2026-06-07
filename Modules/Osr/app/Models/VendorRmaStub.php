<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** OSR-RMA-01 vendor handoff record for a defective unit (v1.0 STUB). */
class VendorRmaStub extends Model
{
    use HasPrefixedId;

    protected $table = 'vendor_rma_stub';

    protected string $idPrefix = 'vrma';

    protected $guarded = [];

    protected $casts = ['shipped_at' => 'datetime'];
}
