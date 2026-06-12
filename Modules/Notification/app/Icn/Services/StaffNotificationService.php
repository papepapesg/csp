<?php

namespace Modules\Notification\Icn\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Icn\StaffNotificationEvents;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationChannelConfig;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;
use Modules\Notification\Models\Icn\StaffNotificationTemplate;
use Modules\Notification\Models\Icn\StaffNotificationUserPref;

/**
 * ICN-01 ingress (§5.1, §11.3). Validates the template + required variables, resolves the
 * candidate group to staff users, computes each recipient's effective channel list
 * (R-ICN-01-D-4/5), persists the parent + per-(recipient x channel) delivery rows, then runs
 * the dispatcher inline. Idempotency-Key replays return the existing notification (R-D-11).
 */
class StaffNotificationService
{
    public function __construct(
        private readonly StaffGroupDirectory $directory,
        private readonly DeliveryDispatcher $dispatcher,
        private readonly EventBus $events,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @return array{notification:StaffNotification, replay:bool}
     */
    public function dispatch(array $data, ?string $idempotencyKey = null): array
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);

        if ($idempotencyKey) {
            $existing = StaffNotification::query()->where('operator_code', $operator)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return ['notification' => $existing, 'replay' => true];
            }
        }

        $templateCode = $data['templateCode'];
        $channelsForTemplate = StaffNotificationTemplate::channelsFor($operator, $templateCode);
        if ($channelsForTemplate === []) {
            throw DomainException::notFound("Template {$templateCode} not found for {$operator}.");
        }
        $this->assertRequiredVariables($operator, $templateCode, $channelsForTemplate, $data['templateVariables'] ?? []);

        $cfg = StaffNotificationChannelConfig::forOperator($operator);
        $ackWindow = (int) ($data['ackWindowHours'] ?? $cfg?->ack_window_hours ?? 24);
        $members = $this->directory->members($operator, $data['candidateGroup']);

        return DB::transaction(function () use ($operator, $data, $templateCode, $channelsForTemplate, $cfg, $ackWindow, $members, $idempotencyKey) {
            $notification = StaffNotification::query()->create([
                'operator_code' => $operator,
                'source_module' => $data['sourceModule'] ?? 'UNKNOWN',
                'source_task_id' => $data['sourceTaskId'] ?? null,
                'source_process_instance' => $data['sourceProcessInstance'] ?? null,
                'source_business_key' => $data['sourceBusinessKey'] ?? null,
                'candidate_group' => $data['candidateGroup'],
                'template_code' => $templateCode,
                'template_variables' => $data['templateVariables'] ?? [],
                'urgency' => $data['urgency'] ?? 'medium',
                'fallback_mode_override' => $data['fallbackModeOverride'] ?? null,
                'deeplink_url' => $data['deeplinkUrl'] ?? ($data['templateVariables']['deeplinkUrl'] ?? null),
                'ack_window_hours' => $ackWindow,
                'expected_recipients' => count($members),
                'status' => StaffNotification::PROCESSING,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addHours($ackWindow),
            ]);

            $this->emit(StaffNotificationEvents::CREATED, $notification, ['expectedRecipients' => count($members)]);

            if ($members === []) {
                $notification->update(['status' => StaffNotification::EXPIRED, 'expiry_reason' => 'NO_RECIPIENTS']);
                $this->emit(StaffNotificationEvents::EXPIRED, $notification, ['reason' => 'NO_RECIPIENTS']);

                return ['notification' => $notification, 'replay' => false];
            }

            foreach ($members as $userId) {
                $this->createDeliveryRows($notification, $operator, $userId, $cfg, $channelsForTemplate);
            }

            $this->dispatcher->dispatchNotification($notification);

            return ['notification' => $notification->refresh(), 'replay' => false];
        });
    }

    /**
     * R-ICN-01-D-4/5: effective channel list = (user priority ∩ enabled) − suppressed,
     * narrowed to channels that have a template variant, with quiet-hours removing all but
     * 'high' urgency. Deliverable channels get PENDING rows; removed channels get SUPPRESSED
     * rows so the audit shows why a recipient wasn't reached on them.
     *
     * @param list<string> $channelsForTemplate
     */
    private function createDeliveryRows(StaffNotification $notification, string $operator, string $userId, ?StaffNotificationChannelConfig $cfg, array $channelsForTemplate): void
    {
        $pref = StaffNotificationUserPref::forUser($userId);
        $priority = $pref?->preferred_priority ?: ($cfg?->default_priority ?? ['EMAIL']);
        $enabled = $pref?->enabled_channels ?: ($cfg?->enabled_channels ?? ['EMAIL']);
        $suppress = $pref?->suppress_channels ?? [];
        $quiet = $this->inQuietHours($pref, $cfg) && $notification->urgency !== 'high';

        $idx = 0;
        foreach ($priority as $channel) {
            if (! in_array($channel, $channelsForTemplate, true) || ! in_array($channel, $enabled, true)) {
                continue; // channel not enabled or no template variant — not part of this recipient's plan
            }
            $removed = in_array($channel, $suppress, true) ? 'USER_SUPPRESSED' : ($quiet ? 'QUIET_HOURS' : null);
            StaffNotificationDelivery::query()->create([
                'notification_id' => $notification->notification_id,
                'operator_code' => $operator,
                'recipient_user_id' => $userId,
                'channel' => $channel,
                'channel_priority_idx' => $idx++,
                'status' => $removed ? StaffNotificationDelivery::SUPPRESSED : StaffNotificationDelivery::PENDING,
                'failure_reason' => $removed,
                'attempts' => 0,
            ]);
        }
    }

    private function inQuietHours(?StaffNotificationUserPref $pref, ?StaffNotificationChannelConfig $cfg): bool
    {
        if (! $pref || ! $pref->quiet_hours_start || ! $pref->quiet_hours_end) {
            return false;
        }
        $tz = $pref->quiet_hours_timezone ?: config('sophix.notification.timezone', config('app.timezone', 'UTC'));
        $now = Carbon::now($tz)->format('H:i');
        $start = substr((string) $pref->quiet_hours_start, 0, 5);
        $end = substr((string) $pref->quiet_hours_end, 0, 5);

        // Window may wrap midnight (22:00-06:00).
        return $start <= $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
    }

    /** @param list<string> $channels @param array<string,mixed> $vars */
    private function assertRequiredVariables(string $operator, string $code, array $channels, array $vars): void
    {
        $required = [];
        foreach ($channels as $channel) {
            $template = StaffNotificationTemplate::resolve($operator, $code, $channel);
            $required = array_merge($required, $template?->required_variables ?? []);
        }
        $missing = array_values(array_diff(array_unique($required), array_keys($vars)));
        // deeplinkUrl is supplied via the notification's deeplink field, not always in vars.
        $missing = array_values(array_diff($missing, ['deeplinkUrl']));
        if ($missing !== []) {
            throw DomainException::ruleRejected('MISSING_REQUIRED_VARIABLES', 'Missing template variables: '.implode(', ', $missing));
        }
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, StaffNotification $notification, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: StaffNotificationEvents::TOPIC,
            payload: ['notificationId' => $notification->notification_id, 'operatorCode' => $notification->operator_code, 'sourceModule' => $notification->source_module, 'candidateGroup' => $notification->candidate_group, 'templateCode' => $notification->template_code] + $extra,
            aggregateType: 'StaffNotification',
            aggregateId: $notification->notification_id,
        ));
    }
}
