<?php

namespace Modules\Reporting\Support;

/**
 * REP-01 single source of truth for event -> metric mapping. Shared by the live
 * projector and the reconciliation re-projection so both derive identical totals.
 */
final class MetricMap
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<int, array{0:string,1:float}>
     */
    public static function for(string $type, array $payload): array
    {
        return match ($type) {
            'SubscriptionCreated' => [['subscriptions_created', 1]],
            'SubscriptionActivated' => [['subscriptions_activated', 1]],
            'SubscriptionTerminated' => [['subscriptions_terminated', 1]],
            'InvoiceGenerated' => [['invoices_generated', 1], ['invoices_amount', (float) ($payload['total'] ?? 0)]],
            'PaymentReceived' => [['payments_count', 1], ['payments_amount', (float) ($payload['amount'] ?? 0)]],
            'InvoicePaid' => [['invoices_paid', 1]],
            'TicketCreated' => [['tickets_created', 1]],
            'TicketResolved' => [['tickets_resolved', 1]],
            'WorkOrderFinalized' => [['work_orders_finalized', 1]],
            'OrderCaptured' => [['orders_captured', 1]],
            'OrderCompleted' => [['orders_completed', 1]],
            'WalletToppedUp' => [['wallet_topups_amount', (float) ($payload['amount'] ?? 0)]],
            default => [],
        };
    }
}
