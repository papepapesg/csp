<?php

namespace Modules\Billing\Tax\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BIL-02-TAX-01 per-operator tax-invoice config (T-4 / S-1 / S-7). enabled gates whether tax
 * invoices are generated at all; signing_service_implementation_ref selects the signer.
 */
class TaxOperatorConfig extends Model
{
    protected $table = 'tax_operator_config';

    public $incrementing = false;

    protected $primaryKey = 'operator_code';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['enabled' => 'bool', 'signing_timeout_seconds' => 'integer', 'config' => 'array'];

    public static function forOperator(string $operator): ?self
    {
        return static::query()->whereKey($operator)->first();
    }

    public static function enabled(string $operator): bool
    {
        return (bool) static::forOperator($operator)?->enabled;
    }
}
