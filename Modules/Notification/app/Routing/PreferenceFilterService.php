<?php

namespace Modules\Notification\Routing;

use Modules\Notification\Models\CustomerNotificationPreference;

/**
 * NOT-01 preferences filter (rule group P + R-3). Drops channels the customer opted out
 * of — but ONLY for MARKETING decisions; TRANSACTIONAL messages cannot be opted out
 * (P-3). A hard-bounced email (email_status=INVALID) drops the email channel regardless
 * of category (F-5). Returns the surviving decisions; the caller suppresses when empty.
 */
class PreferenceFilterService
{
    /**
     * @param list<ChannelDecision> $decisions
     * @return list<ChannelDecision>
     */
    public function apply(?string $customerId, array $decisions): array
    {
        $pref = $customerId ? CustomerNotificationPreference::forCustomer($customerId) : null;

        return array_values(array_filter($decisions, function (ChannelDecision $d) use ($pref) {
            if (! $pref) {
                return true; // no stored preference -> operator defaults (opt-in) apply
            }
            // Hard-bounced email: skip the channel entirely until admin updates the address.
            if ($d->channel === 'EMAIL' && $pref->email_status === CustomerNotificationPreference::EMAIL_INVALID) {
                return false;
            }
            // Opt-out only bites marketing.
            if ($d->isMarketing()) {
                if ($d->channel === 'EMAIL' && ! $pref->email_opt_in) {
                    return false;
                }
                if ($d->channel === 'SMS' && ! $pref->sms_opt_in) {
                    return false;
                }
            }

            return true;
        }));
    }
}
