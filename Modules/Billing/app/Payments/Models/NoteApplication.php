<?php

namespace Modules\Billing\Payments\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-01-CN-01 note_application_ledger row — append-only record of a credit /
 * debit note application against one target (invoice, wallet or the account
 * credit balance). A note that splits (invoice + surplus, or auto-allocation
 * across open invoices) writes several rows in one transaction.
 *
 * @property string $note_id
 * @property string $status
 */
class NoteApplication extends Model
{
    use HasPrefixedId;

    public const APPLIED = 'APPLIED';

    public const FAILED = 'FAILED';

    public const TARGET_INVOICE = 'INVOICE';

    public const TARGET_WALLET = 'WALLET';

    public const TARGET_CREDIT_BALANCE = 'CREDIT_BALANCE';

    protected $table = 'note_application_ledger';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'na';

    protected $guarded = [];

    protected $casts = [
        'note_amount' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'target_balance_before' => 'decimal:2',
        'target_balance_after' => 'decimal:2',
        'applied_at' => 'datetime',
    ];
}
