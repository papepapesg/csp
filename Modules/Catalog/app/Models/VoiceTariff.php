<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PLM config catalog — voice_tariff. */
class VoiceTariff extends Model
{
    use HasPrefixedId;

    protected $table = 'voice_tariff';

    protected $primaryKey = 'voice_tariff_id';

    protected string $idPrefix = 'vtar';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
