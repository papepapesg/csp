<?php

namespace Modules\Catalog\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PLM config catalog — wallet_type. */
class WalletType extends Model
{
    use HasPrefixedId;

    protected $table = 'wallet_type';

    protected $primaryKey = 'wallet_type_id';

    protected string $idPrefix = 'wtyp';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
