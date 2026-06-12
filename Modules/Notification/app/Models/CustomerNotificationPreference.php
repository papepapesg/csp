<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 per-customer notification preference (R-NOT-01-P-1). Opt-in flags apply to
 * MARKETING only — transactional messages are always sent (R-NOT-01-P-3). email_status
 * latches bounce state: SOFT_BOUNCED keeps trying, INVALID skips the email channel
 * entirely until an admin updates the address (R-NOT-01-F-5).
 *
 * @property string $id
 * @property bool $email_opt_in
 * @property bool $sms_opt_in
 * @property string $email_status
 */
class CustomerNotificationPreference extends Model
{
    use HasPrefixedId;

    public const EMAIL_VALID = 'VALID';
    public const EMAIL_SOFT_BOUNCED = 'SOFT_BOUNCED';
    public const EMAIL_INVALID = 'INVALID';

    protected $table = 'customer_notification_preference';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'cnp';

    protected $guarded = [];

    protected $casts = [
        'email_opt_in' => 'bool',
        'sms_opt_in' => 'bool',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public static function forCustomer(string $customerId): ?self
    {
        return static::query()->where('customer_id', $customerId)->first();
    }
}
