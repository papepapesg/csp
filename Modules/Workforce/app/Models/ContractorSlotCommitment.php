<?php

namespace Modules\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

/** EM-02 §3.6 immutable slot-commitment ledger (capacity is derived from ACTIVE rows). */
class ContractorSlotCommitment extends Model
{
    public const ACTIVE = 'ACTIVE';
    public const CONSUMED = 'CONSUMED';
    public const RELEASED = 'RELEASED';
    public const EXPIRED = 'EXPIRED';

    protected $table = 'contractor_slot_commitment';

    protected $primaryKey = 'commitment_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['committed_for_datetime' => 'datetime', 'qty' => 'integer', 'consumed_at' => 'datetime', 'released_at' => 'datetime', 'expired_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'commitment_id';
    }
}
