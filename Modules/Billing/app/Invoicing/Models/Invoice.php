<?php

namespace Modules\Billing\Invoicing\Models;
use Modules\Billing\Invoicing\Models\InvoiceLine;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BIL-02 invoice header. amount_due = total_amount - amount_paid drives status.
 *
 * @property string $invoice_id
 * @property string $status
 */
class Invoice extends Model
{
    use HasPrefixedId;
    use \App\Foundation\Tenancy\BelongsToOperator;

    public const OPEN = 'OPEN';

    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const PAID = 'PAID';

    public const VOID = 'VOID';

    public const OVERDUE = 'OVERDUE';

    /** Note documents (CREDIT_NOTE / DEBIT_NOTE) are never receivables. */
    public const ISSUED = 'ISSUED';

    public const CREDIT_NOTE = 'CREDIT_NOTE';

    public const DEBIT_NOTE = 'DEBIT_NOTE';

    /** Signed fiscal document (TAX-01) — immutable, never adjusted or reversed. */
    public const TAX = 'TAX';

    protected $table = 'invoice';

    protected $primaryKey = 'invoice_id';

    protected string $idPrefix = 'inv';

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'datetime',
        'due_date' => 'datetime',
        'tax_summary' => 'array',
        'customer_snapshot' => 'array',
        'subtotal_amount' => 'decimal:2',
        'tax_amount_total' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_due' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'invoice_id';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id', 'invoice_id');
    }
}
