<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-01 / SALES-01 / USSD — sales_lead. */
class SalesLead extends Model
{
    use HasPrefixedId;

    protected $table = 'sales_lead';

    protected $primaryKey = 'lead_id';

    protected string $idPrefix = 'lead';

    protected $keyType = 'string';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'lead_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
