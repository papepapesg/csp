<?php

namespace Modules\Billing\Events;

/**
 * Billing domain-event types + topic (BIL-02 / BIL-01-PAY-01 / BIL-05).
 */
final class BillingEvents
{
    public const TOPIC = 'billing.money';

    public const INVOICE_GENERATED = 'InvoiceGenerated';

    public const INVOICE_PAID = 'InvoicePaid';

    public const PAYMENT_RECEIVED = 'PaymentReceived';

    public const PAYMENT_APPLIED = 'PaymentApplied';

    public const WALLET_CREDITED = 'WalletCredited';

    public const WALLET_DEBITED = 'WalletDebited';

    public const WALLET_TOPPED_UP = 'WalletToppedUp';
}
