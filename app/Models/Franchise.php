<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-01 / SALES-01 / USSD — franchise. */
class Franchise extends Model
{
    use HasPrefixedId;

    protected $table = 'franchise';

    protected $primaryKey = 'franchise_id';

    protected string $idPrefix = 'frn';

    protected $keyType = 'string';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'franchise_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
