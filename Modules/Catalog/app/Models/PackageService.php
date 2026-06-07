<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-01 package -> service composition row.
 */
class PackageService extends Model
{
    use HasPrefixedId;

    protected $table = 'package_service';

    protected string $idPrefix = 'pks';

    protected $guarded = [];

    protected $casts = ['sequence' => 'integer'];
}
