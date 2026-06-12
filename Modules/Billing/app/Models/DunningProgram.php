<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-04 versioned dunning program (DD §data model). The operator policy surface: an ordered
 * level_definitions array declares each escalation step's grace period, the workflow intent to
 * fire, and its action_payload. Edits create new versions; a dunning_state pins its version at
 * entry (R-BIL-04-C-1) so in-flight episodes finish under the policy in effect when they began.
 *
 * @property string $id
 * @property int $version
 */
class DunningProgram extends Model
{
    use HasPrefixedId;

    public const POSTPAID = 'POSTPAID';
    public const PREPAID = 'PREPAID';
    public const PREPAYMENT = 'PREPAYMENT';

    public const WARNING_ONLY = 'WARNING_ONLY';
    public const RESTRICTION_ADD = 'RESTRICTION_ADD';
    public const SUSPEND_NP = 'SUSPEND_NP';
    public const TERMINATION = 'TERMINATION';

    protected $table = 'dunning_program';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'dprg';

    protected $guarded = [];

    protected $casts = [
        'level_definitions' => 'array',
        'pre_termination_review_required' => 'bool',
        'version' => 'int',
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    /** @return array<string,mixed>|null the level config for a 1-based level number */
    public function levelDef(int $level): ?array
    {
        foreach ($this->level_definitions ?? [] as $def) {
            if ((int) ($def['level'] ?? 0) === $level) {
                return $def;
            }
        }

        return null;
    }

    public function maxLevel(): int
    {
        return (int) collect($this->level_definitions ?? [])->max('level');
    }

    public function graceDays(int $level): int
    {
        return (int) ($this->levelDef($level)['grace_period_days'] ?? 0);
    }

    public function actionIntent(int $level): ?string
    {
        return $this->levelDef($level)['action_workflow_intent'] ?? null;
    }

    /** @return array<string,mixed> */
    public function actionPayload(int $level): array
    {
        return (array) ($this->levelDef($level)['action_payload'] ?? []);
    }
}
