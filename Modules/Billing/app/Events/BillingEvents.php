<?php

namespace Modules\Billing\Events;

/**
 * Billing domain-event types + topic (BIL-02 / BIL-01-PAY-01 / BIL-05).
 */
final class BillingEvents
{
    public const TOPIC = 'billing.money';

    public const INVOICE_GENERATED = 'InvoiceGenerated';

    public const INVOICE_CANCELLED = 'InvoiceCancelled';  // BIL-02-GEN-01 bulk reversal (R-GEN-01-R-3)

    public const BULK_REVERSAL_COMPLETED = 'BulkReversalCompleted';

    public const CYCLE_BILLED = 'SubscriptionCycleBilled';

    // BIL-03 cycle close (per-subscription boundary)
    public const CYCLE_CLOSED = 'CycleClosed';            // POSTPAID: cycle invoice generated

    public const CYCLE_ACTIVATED = 'CycleActivated';      // PREPAID/PREPAYMENT: new cycle paid + opened

    public const CYCLE_PAYMENT_MISSED = 'CyclePaymentMissed'; // boundary unpaid → dunning/freeze

    public const INVOICE_PAID = 'InvoicePaid';

    public const PAYMENT_RECEIVED = 'PaymentReceived';

    public const PAYMENT_APPLIED = 'PaymentApplied';

    public const WALLET_CREDITED = 'WalletCredited';

    public const WALLET_DEBITED = 'WalletDebited';

    public const WALLET_TOPPED_UP = 'WalletToppedUp';

    public const TAX_INVOICE_ISSUED = 'TaxInvoiceIssued';

    public const DUNNING_STAGE_ADVANCED = 'DunningStageAdvanced';

    public const DUNNING_CLEARED = 'DunningCleared';

    public const SUBSCRIPTION_SUSPENDED_NP = 'SubscriptionSuspendedForNonPayment';

    // BIL-02-ADJ-01 adjustment lifecycle
    public const ADJUSTMENT_PROPOSED = 'AdjustmentProposed';

    public const ADJUSTMENT_APPROVED = 'AdjustmentApproved';

    public const ADJUSTMENT_REJECTED = 'AdjustmentRejected';

    // BIL-02-GEN-01 note issuance → BIL-01-CN-01 note application
    public const CREDIT_NOTE_ISSUED = 'CreditNoteIssued';

    public const DEBIT_NOTE_ISSUED = 'DebitNoteIssued';

    public const CREDIT_NOTE_APPLIED = 'CreditNoteApplied';

    public const DEBIT_NOTE_APPLIED = 'DebitNoteApplied';

    public const DEBIT_NOTE_APPLICATION_FAILED = 'DebitNoteApplicationFailed';

    public const NOTE_APPLICATION_FAILED = 'NoteApplicationFailed';

    // BIL-CFG-01 catalog administration
    public const BILLABLE_EVENT_CHANGED = 'BillableEventCatalogChanged';
}
