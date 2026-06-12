<?php

namespace Modules\Notification\Models\Icn;

use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * ICN-01 staff template (§3.1). Identified by (operator_code, template_code, channel) — the
 * same code has independent per-channel variants (verbose EMAIL vs terse SLACK card). PII
 * minimization is a content-review rule (R-ICN-01-D-14), not enforced by code.
 */
class StaffNotificationTemplate extends Model
{
    protected $table = 'staff_notification_template';

    public $incrementing = false;

    protected $primaryKey = 'template_code'; // composite in practice; queries use the full key

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'required_variables' => 'array',
        'optional_variables' => 'array',
        'enabled' => 'bool',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public static function resolve(string $operator, string $code, string $channel): ?self
    {
        return static::query()->where('operator_code', $operator)->where('template_code', $code)
            ->where('channel', $channel)->where('enabled', true)->first();
    }

    /** Distinct channel variants that exist for a (operator, code) — used for the TEMPLATE_NOT_FOUND check. */
    public static function channelsFor(string $operator, string $code): array
    {
        return static::query()->where('operator_code', $operator)->where('template_code', $code)
            ->where('enabled', true)->pluck('channel')->all();
    }
}
