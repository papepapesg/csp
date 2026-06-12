<?php

namespace Modules\Notification\Models\Icn;

use Illuminate\Database\Eloquent\Model;

/**
 * ICN-01 adapter binding (§3.6): the MAPPING (operator, channel) -> concrete adapter_impl +
 * opaque config_jsonb. This is where SMTP host / Slack workspace / Teams webhook / Graph
 * tenant live (secret REFS only). Provider swap = a data update here, no code/domain change.
 * Exactly one binding per (operator, channel).
 */
class StaffNotificationAdapterBinding extends Model
{
    protected $table = 'staff_notification_adapter_binding';

    public $incrementing = false;

    protected $primaryKey = 'operator_code';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'config_jsonb' => 'array',
        'enabled' => 'bool',
    ];

    public static function resolve(string $operator, string $channel): ?self
    {
        return static::query()->where('operator_code', $operator)->where('channel', $channel)->first();
    }
}
