<?php

namespace Modules\Notification\Routing;

use Modules\Notification\Models\CustomerNotificationPreference;

/**
 * NOT-01 regulatory + time-window filter (R-NOT-01-R-4 / P-5). Non-urgent messages
 * outside the allowed window are deferred (deferUntil set to the next window start), not
 * dropped. Urgent decisions bypass the window. Two windows compose: the operator's
 * regulatory window (per-operator config, e.g. no SMS 21:00-07:00) and the customer's
 * preferred time-window — a message must satisfy both.
 */
class RegulatoryFilterService
{
    /**
     * @param list<ChannelDecision> $decisions
     * @return list<ChannelDecision>
     */
    public function apply(string $operator, ?string $customerId, array $decisions, ?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $tz = config('sophix.notification.timezone', config('app.timezone', 'UTC'));
        $localNow = \Illuminate\Support\Carbon::instance(\Illuminate\Support\Carbon::parse($now))->setTimezone($tz);
        $pref = $customerId ? CustomerNotificationPreference::forCustomer($customerId) : null;

        foreach ($decisions as $d) {
            if ($d->isUrgent()) {
                continue; // urgent bypasses all windows
            }
            $start = $this->regulatoryStart($operator, $d->channel);
            $end = $this->regulatoryEnd($operator, $d->channel);
            // Customer preferred window narrows the operator window.
            if ($pref && $pref->preferred_time_window_start && $pref->preferred_time_window_end) {
                $start = $this->maxTime($start, substr((string) $pref->preferred_time_window_start, 0, 5));
                $end = $this->minTime($end, substr((string) $pref->preferred_time_window_end, 0, 5));
            }
            if (! $this->withinWindow($localNow, $start, $end)) {
                $d->deferUntil = $this->nextWindowStart($localNow, $start)->setTimezone('UTC');
            }
        }

        return $decisions;
    }

    private function regulatoryStart(string $operator, string $channel): string
    {
        return (string) config("sophix.notification.window.{$channel}.start", config('sophix.notification.window.default.start', '00:00'));
    }

    private function regulatoryEnd(string $operator, string $channel): string
    {
        return (string) config("sophix.notification.window.{$channel}.end", config('sophix.notification.window.default.end', '23:59'));
    }

    private function withinWindow(\Illuminate\Support\Carbon $now, string $start, string $end): bool
    {
        $hm = $now->format('H:i');

        return $hm >= $start && $hm <= $end;
    }

    private function nextWindowStart(\Illuminate\Support\Carbon $now, string $start): \Illuminate\Support\Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $start));
        $candidate = $now->copy()->setTime($h, $m, 0);
        if ($candidate->lessThanOrEqualTo($now)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function maxTime(string $a, string $b): string
    {
        return $a >= $b ? $a : $b;
    }

    private function minTime(string $a, string $b): string
    {
        return $a <= $b ? $a : $b;
    }
}
