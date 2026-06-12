<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Notification\Models\Icn\StaffNotificationAdapterBinding;
use Modules\Notification\Models\Icn\StaffNotificationChannelConfig;
use Modules\Notification\Models\Icn\StaffNotificationTemplate;

/**
 * ICN-01 default WIK (Kenya) setup: domain channel config, adapter bindings (EMAIL via
 * smtp-classic, SLACK via slack-bot-api, IN_APP_PUSH via inapp-websocket-fanout), and a
 * representative slice of the v1.0 KE template seed across channel variants. Group memberships
 * and per-user prefs are seeded by tests / per-deployment scripts.
 */
class Icn01Seeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');

        StaffNotificationChannelConfig::query()->updateOrCreate(['operator_code' => $op], [
            'enabled_channels' => ['EMAIL', 'SLACK', 'IN_APP_PUSH'],
            'default_priority' => ['IN_APP_PUSH', 'SLACK', 'EMAIL'],
            'fallback_mode' => StaffNotificationChannelConfig::PARALLEL,
            'retry_max_attempts' => 3,
            'retry_backoff_seconds' => [30, 120, 600],
            'ack_window_hours' => 24,
            'updated_at' => now(),
        ]);

        $bindings = [
            ['EMAIL', 'smtp-classic', ['host' => 'smtp-relay-internal.wik.sophix.local', 'port' => 587, 'from' => 'sophix-noreply@wik.sophix.local', 'auth_vault_ref' => 'secret/icn/wik/smtp']],
            ['SLACK', 'slack-bot-api', ['workspace_id' => 'T7K1S0PH1XKE', 'bot_token_vault_ref' => 'secret/icn/wik/slack/bot-token']],
            ['IN_APP_PUSH', 'inapp-websocket-fanout', ['ws_endpoint' => 'wss://push.wik.sophix.com/staff']],
        ];
        foreach ($bindings as [$channel, $impl, $config]) {
            StaffNotificationAdapterBinding::query()->updateOrCreate(
                ['operator_code' => $op, 'channel' => $channel],
                ['adapter_impl' => $impl, 'config_jsonb' => $config, 'enabled' => true, 'updated_by' => 'seed'],
            );
        }

        // [template_code, channel, subject, body, required_variables, urgency]
        $templates = [
            ['kyc-l1-approval-needed', 'EMAIL', '[KYC] L1 approval needed: {{customerName}}', "Hi,\n\nCustomer {{customerName}} (Order {{orderId}}) is awaiting L1 KYC approval at {{franchiseCode}}.\n\nReview: {{deeplinkUrl}}\n\n— Sophix", ['customerName', 'orderId', 'franchiseCode', 'deeplinkUrl'], 'medium'],
            ['kyc-l1-approval-needed', 'SLACK', null, "*KYC L1 approval needed*\n• Customer: {{customerName}}\n• Franchise: {{franchiseCode}}\n• <{{deeplinkUrl}}|Open in BO UI>", ['customerName', 'franchiseCode', 'deeplinkUrl'], 'medium'],
            ['kyc-l1-approval-needed', 'IN_APP_PUSH', null, 'KYC L1 needed for {{customerName}} ({{franchiseCode}})', ['customerName', 'franchiseCode'], 'medium'],
            ['install-wo-awaiting-dispatcher', 'EMAIL', '[WO] Install awaiting tech assignment: {{accountNumber}}', "New install WO {{workOrderId}} for {{customerName}} at {{serviceAddress}}.\n\nPlease assign tech: {{deeplinkUrl}}", ['workOrderId', 'customerName', 'serviceAddress', 'deeplinkUrl'], 'medium'],
            ['install-wo-awaiting-dispatcher', 'SLACK', null, "*Install WO awaiting tech assignment*\n• WO: {{workOrderId}}\n• {{customerName}} @ {{serviceAddress}}\n• <{{deeplinkUrl}}|Assign>", ['workOrderId', 'customerName', 'serviceAddress', 'deeplinkUrl'], 'medium'],
            ['install-wo-awaiting-dispatcher', 'IN_APP_PUSH', null, 'Install WO {{workOrderId}} awaiting assignment', ['workOrderId'], 'medium'],
            ['refund-approval-needed', 'EMAIL', '[Finance] Refund {{amount}} {{currency}} needs approval', "Refund request {{refundRequestId}} for {{customerName}} ({{accountNumber}}): {{amount}} {{currency}}.\n\nReason: {{reasonCode}}\n\nApprove: {{deeplinkUrl}}", ['refundRequestId', 'customerName', 'accountNumber', 'amount', 'currency', 'reasonCode', 'deeplinkUrl'], 'high'],
            ['refund-approval-needed', 'SLACK', null, "*Refund approval needed* — {{amount}} {{currency}} for {{customerName}}\n<{{deeplinkUrl}}|Approve>", ['amount', 'currency', 'customerName', 'deeplinkUrl'], 'high'],
            ['dunning-l3-escalation-internal', 'EMAIL', '[Dunning L3] Account {{accountNumber}} — overdue {{daysOverdue}}d', "Account {{accountNumber}} ({{customerName}}) entered Dunning L3 (suspension).\n\nDays overdue: {{daysOverdue}}\nAmount: {{amountDue}} {{currency}}\n\nReview: {{deeplinkUrl}}", ['accountNumber', 'customerName', 'daysOverdue', 'amountDue', 'currency', 'deeplinkUrl'], 'high'],
        ];
        foreach ($templates as [$code, $channel, $subject, $body, $required, $urgency]) {
            StaffNotificationTemplate::query()->updateOrCreate(
                ['operator_code' => $op, 'template_code' => $code, 'channel' => $channel],
                ['subject' => $subject, 'body_template' => $body, 'required_variables' => $required, 'urgency_default' => $urgency, 'display_name' => $code, 'enabled' => true],
            );
        }
    }
}
