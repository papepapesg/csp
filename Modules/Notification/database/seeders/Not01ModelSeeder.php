<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Notification\Models\ChannelOperatorConfig;
use Modules\Notification\Models\NotificationRoutingRule;
use Modules\Notification\Models\Template;

/**
 * NOT-01 default operator setup (illustrative, per the DD sample data): per-channel
 * config, routing rules for the common billing events, and the format-decomposed template
 * family for an invoice. Operators commit their own sets per environment; this is the
 * shipped default for WIK.
 */
class Not01ModelSeeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');

        // ---- channel_operator_config ----
        $channels = [
            ['EMAIL', 'smtp.default', 'billing@wananchi.co.ke', ['attach_pdf' => true]],
            ['SMS', 'sms.africastalking', 'WANANCHI', ['max_parts' => 4]],
        ];
        foreach ($channels as [$channel, $impl, $sender, $cfg]) {
            ChannelOperatorConfig::query()->updateOrCreate(
                ['operator_code' => $op, 'channel' => $channel],
                ['adapter_implementation' => $impl, 'sender_identifier' => $sender, 'credentials_ref' => "vault://notification/{$op}/{$channel}", 'additional_config' => $cfg, 'enabled' => true],
            );
        }

        // ---- notification_routing_rule ----
        $rules = [
            // event_type, channel, priority, urgency, category, purpose, needs_pdf
            ['InvoiceIssued', 'EMAIL', 1, 'NORMAL', 'TRANSACTIONAL', 'INVOICE_CYCLE_POSTPAID', true],
            ['InvoiceIssued', 'SMS', 2, 'NORMAL', 'TRANSACTIONAL', 'INVOICE_CYCLE_POSTPAID', false],
            ['InvoiceOverdue', 'EMAIL', 1, 'URGENT', 'TRANSACTIONAL', 'DUNNING_STEP_1', true],
            ['InvoiceOverdue', 'SMS', 2, 'URGENT', 'TRANSACTIONAL', 'DUNNING_STEP_1', false],
            ['PtpRegistered', 'SMS', 1, 'NORMAL', 'TRANSACTIONAL', 'PTP_CONFIRMATION', false],
            ['PtpRegistered', 'EMAIL', 2, 'NORMAL', 'TRANSACTIONAL', 'PTP_CONFIRMATION', false],
            ['PaymentApplied', 'SMS', 1, 'NORMAL', 'TRANSACTIONAL', 'PAYMENT_RECEIPT', false],
            // BIL-04 dunning notice — the operator picks the channels here (EMAIL + SMS by
            // default; swap to WHATSAPP/etc. by editing these rows, no code change).
            ['DunningStageAdvanced', 'EMAIL', 1, 'NORMAL', 'TRANSACTIONAL', 'DUNNING_NOTICE', false],
            ['DunningStageAdvanced', 'SMS', 2, 'NORMAL', 'TRANSACTIONAL', 'DUNNING_NOTICE', false],
            // ILM-CFG-01: a customer-visible account status change notifies the customer.
            ['CustomerAccountStatusChanged', 'SMS', 1, 'NORMAL', 'TRANSACTIONAL', 'ACCOUNT_STATUS_CHANGE', false],
            ['CustomerAccountStatusChanged', 'EMAIL', 2, 'NORMAL', 'TRANSACTIONAL', 'ACCOUNT_STATUS_CHANGE', false],
        ];
        foreach ($rules as [$event, $channel, $priority, $urgency, $category, $purpose, $needsPdf]) {
            NotificationRoutingRule::query()->updateOrCreate(
                ['operator_code' => $op, 'event_type' => $event, 'channel' => $channel],
                ['template_purpose_code' => $purpose, 'priority' => $priority, 'urgency' => $urgency, 'category' => $category, 'needs_pdf' => $needsPdf, 'enabled' => true],
            );
        }

        // ---- template (format-decomposed, en) ----
        $templates = [
            ['PDF', 'INVOICE_CYCLE_POSTPAID', 'HTML_TO_PDF', '<h1>Invoice {{invoiceNumber}}</h1><p>{{currency}} {{amount}} due {{dueDate}}</p>'],
            ['EMAIL_SUBJECT', 'INVOICE_CYCLE_POSTPAID', 'HANDLEBARS', 'Your invoice {{invoiceNumber}}'],
            ['EMAIL_HTML', 'INVOICE_CYCLE_POSTPAID', 'HANDLEBARS', '<p>Dear customer,</p><p>Invoice {{invoiceNumber}} for {{currency}} {{amount}} is due on {{dueDate}}.</p>'],
            ['EMAIL_TEXT', 'INVOICE_CYCLE_POSTPAID', 'HANDLEBARS', "Dear customer,\nInvoice {{invoiceNumber}} for {{currency}} {{amount}} is due on {{dueDate}}."],
            ['SMS_TEXT', 'INVOICE_CYCLE_POSTPAID', 'HANDLEBARS', 'Invoice {{invoiceNumber}}: {{currency}} {{amount}} due {{dueDate}}.'],
            ['SMS_TEXT', 'PTP_CONFIRMATION', 'HANDLEBARS', "We've registered your promise to pay {{amount}} {{currency}} by {{payByDate}}."],
            ['EMAIL_SUBJECT', 'PTP_CONFIRMATION', 'HANDLEBARS', 'Promise to pay registered'],
            ['EMAIL_HTML', 'PTP_CONFIRMATION', 'HANDLEBARS', '<p>Your promise to pay {{amount}} {{currency}} by {{payByDate}} is registered.</p>'],
            ['EMAIL_TEXT', 'PTP_CONFIRMATION', 'HANDLEBARS', 'Your promise to pay {{amount}} {{currency}} by {{payByDate}} is registered.'],
            ['SMS_TEXT', 'PAYMENT_RECEIPT', 'HANDLEBARS', 'Payment of {{currency}} {{amount}} received. Thank you.'],
            ['SMS_TEXT', 'DUNNING_NOTICE', 'HANDLEBARS', 'Your account is overdue (stage {{levelName}}). Please pay to avoid service interruption.'],
            ['EMAIL_SUBJECT', 'DUNNING_NOTICE', 'HANDLEBARS', 'Action needed: your account is overdue'],
            ['EMAIL_HTML', 'DUNNING_NOTICE', 'HANDLEBARS', '<p>Your account is overdue (stage {{levelName}}). Please settle the balance to avoid service interruption.</p>'],
            ['EMAIL_TEXT', 'DUNNING_NOTICE', 'HANDLEBARS', 'Your account is overdue (stage {{levelName}}). Please settle the balance to avoid service interruption.'],
            ['SMS_TEXT', 'ACCOUNT_STATUS_CHANGE', 'HANDLEBARS', 'Your account status has changed to {{status}}.'],
            ['EMAIL_SUBJECT', 'ACCOUNT_STATUS_CHANGE', 'HANDLEBARS', 'Your account status has changed'],
            ['EMAIL_HTML', 'ACCOUNT_STATUS_CHANGE', 'HANDLEBARS', '<p>Your account status has changed to {{status}}.</p>'],
            ['EMAIL_TEXT', 'ACCOUNT_STATUS_CHANGE', 'HANDLEBARS', 'Your account status has changed to {{status}}.'],
        ];
        foreach ($templates as [$format, $purpose, $engine, $payload]) {
            Template::query()->updateOrCreate(
                ['operator_code' => $op, 'template_format' => $format, 'template_purpose_code' => $purpose, 'locale' => 'en', 'version' => 1],
                ['status' => Template::STATUS_ACTIVE, 'engine_type' => $engine, 'template_payload' => $payload, 'created_by' => 'seed'],
            );
        }
    }
}
