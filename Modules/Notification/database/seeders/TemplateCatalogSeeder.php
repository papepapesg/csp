<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Notification\Models\InvoiceTemplate;
use Modules\Notification\Models\NotificationTemplate;

/**
 * NOT-01 default template set: per-channel notification templates for common
 * lifecycle/billing events and a standard invoice layout. Authored further in the
 * studio. The same template_code renders differently per channel.
 */
class TemplateCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // [template_code, channel, subject, body, variables]
        $templates = [
            ['SUBSCRIPTION_ACTIVATED', 'SMS', null, 'Hi {{customerName}}, your service is now ACTIVE. Welcome aboard!', ['customerName']],
            ['SUBSCRIPTION_ACTIVATED', 'EMAIL', 'Your service is active', 'Dear {{customerName}},\n\nYour subscription {{subscriptionId}} is now active.\n\nThank you.', ['customerName', 'subscriptionId']],
            ['SUBSCRIPTION_SUSPENDED_NP', 'SMS', null, 'Your service is suspended for non-payment. Pay {{amountDue}} to restore.', ['amountDue']],
            ['INVOICE_ISSUED', 'SMS', null, 'Invoice {{invoiceNumber}}: {{currency}} {{amount}} due {{dueDate}}.', ['invoiceNumber', 'currency', 'amount', 'dueDate']],
            ['INVOICE_ISSUED', 'EMAIL', 'Your invoice {{invoiceNumber}}', "Dear customer,\n\nInvoice {{invoiceNumber}} for {{currency}} {{amount}} is due on {{dueDate}}.", ['invoiceNumber', 'currency', 'amount', 'dueDate']],
        ];
        foreach ($templates as [$code, $channel, $subject, $body, $vars]) {
            NotificationTemplate::query()->updateOrCreate(
                ['operator_code' => $operator, 'template_code' => $code, 'channel' => $channel, 'locale' => 'en'],
                ['subject' => $subject, 'body' => $body, 'variables' => $vars, 'status' => NotificationTemplate::ACTIVE, 'updated_by' => 'seed'],
            );
        }

        InvoiceTemplate::query()->updateOrCreate(
            ['operator_code' => $operator, 'code' => 'STANDARD'],
            [
                'name' => 'Standard Invoice',
                'layout' => [
                    'header' => ['logo' => true, 'operatorName' => true, 'invoiceNumber' => true, 'issueDate' => true, 'dueDate' => true],
                    'billTo' => ['customerName' => true, 'accountId' => true, 'address' => true],
                    'lines' => ['columns' => ['description', 'quantity', 'unitPrice', 'tax', 'amount']],
                    'totals' => ['subtotal' => true, 'tax' => true, 'total' => true, 'amountDue' => true],
                    'footer' => ['paymentInstructions' => '{{paymentInstructions}}', 'taxNote' => true],
                ],
                'status' => InvoiceTemplate::ACTIVE,
                'updated_by' => 'seed',
            ],
        );
    }
}
