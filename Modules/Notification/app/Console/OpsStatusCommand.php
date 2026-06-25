<?php

namespace Modules\Notification\Console;

use Illuminate\Console\Command;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;
use Modules\Notification\Models\NotificationDeliveryAttempt;
use Modules\Notification\Models\RenderFailureQueue;

/**
 * Ops review: a one-glance health summary of the notification work queues an operator
 * needs to drain (read-only) — covers both NOT-01 customer delivery attempts / render
 * failures and ICN-01 staff deliveries.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:notification:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of notification items needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $rows = [
            // NOT-01 customer delivery attempts.
            ['Customer attempts: pending retry (due)', $scope(NotificationDeliveryAttempt::query())->where('status', NotificationDeliveryAttempt::PENDING_RETRY)->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })->count()],
            ['Customer attempts: pending retry (scheduled)', $scope(NotificationDeliveryAttempt::query())->where('status', NotificationDeliveryAttempt::PENDING_RETRY)->where('next_attempt_at', '>', now())->count()],
            ['Customer attempts: failed', $scope(NotificationDeliveryAttempt::query())->where('status', NotificationDeliveryAttempt::FAILED)->count()],
            ['Customer attempts: escalated', $scope(NotificationDeliveryAttempt::query())->where('status', NotificationDeliveryAttempt::ESCALATED)->count()],
            // NOT-01 render failures.
            ['Render failures: pending retry', $scope(RenderFailureQueue::query())->where('status', RenderFailureQueue::PENDING_RETRY)->count()],
            ['Render failures: gave up (auto)', $scope(RenderFailureQueue::query())->where('status', RenderFailureQueue::GAVE_UP_AUTO)->count()],
            // ICN-01 staff deliveries.
            ['Staff deliveries: pending', $scope(StaffNotificationDelivery::query())->where('status', StaffNotificationDelivery::PENDING)->count()],
            ['Staff deliveries: failed', $scope(StaffNotificationDelivery::query())->where('status', StaffNotificationDelivery::FAILED)->count()],
            ['Staff deliveries: terminally failed', $scope(StaffNotificationDelivery::query())->where('status', StaffNotificationDelivery::TERMINALLY_FAILED)->count()],
            // ICN-01 staff notifications still open past their ack window.
            ['Staff notifications: open past ack window', $scope(StaffNotification::query())->whereIn('status', [StaffNotification::PROCESSING, StaffNotification::DISPATCHED])->where('expires_at', '<=', now())->count()],
        ];

        $this->info('Notification ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Drain hints: customer retry → sophix:notification:retry-dispatch · render → sophix:notification:retry-render · staff → sophix:icn:retry / sophix:icn:expire · or sophix:notification:retry-fix');

        return self::SUCCESS;
    }
}
