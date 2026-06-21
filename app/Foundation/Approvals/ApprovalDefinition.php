<?php

namespace App\Foundation\Approvals;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** EM-CFG-04 approval policy row. */
class ApprovalDefinition extends Model
{
    use HasPrefixedId;

    protected $table = 'approval_definition';

    protected $primaryKey = 'definition_id';

    protected string $idPrefix = 'appd';

    protected $guarded = [];

    protected $casts = ['approver_roles' => 'array', 'active' => 'boolean', 'threshold_amount' => 'decimal:2'];

    /** Ordered chain of stages (empty ⇒ legacy single implicit stage from the flat columns). */
    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalStage::class, 'definition_id', 'definition_id')->orderBy('sequence');
    }

    /**
     * Canonical way to declare a policy AND its ordered chain in one idempotent call (used by every
     * seeder). A single-element `$stages` is a one-stage chain; supply more for a hierarchy. The flat
     * header columns mirror stage 1 so legacy reads + the notification snapshot stay meaningful.
     *
     * @param  list<array<string,mixed>>  $stages  each: name?, approver_kind?(ROLE|USER), approver_roles?,
     *                                             approver_user_ref?, approver_email?, required_approvals?, allow_requester?
     * @param  array<string,mixed>  $opts  threshold_amount?, active?
     */
    public static function defineChain(string $operator, string $entityType, ?string $action, array $stages, array $opts = []): self
    {
        $stages = array_values($stages);
        $first = $stages[0] ?? [];
        $def = static::query()->updateOrCreate(
            ['operator_code' => $operator, 'entity_type' => $entityType, 'action' => $action],
            [
                'definition_id' => Id::make('appd'),
                'threshold_amount' => $opts['threshold_amount'] ?? null,
                'approver_roles' => $first['approver_roles'] ?? [],
                'required_approvals' => $first['required_approvals'] ?? 1,
                'allow_requester' => $first['allow_requester'] ?? false,
                'active' => $opts['active'] ?? true,
            ],
        );

        foreach ($stages as $i => $s) {
            ApprovalStage::query()->updateOrCreate(
                ['definition_id' => $def->definition_id, 'sequence' => $i + 1],
                [
                    'stage_id' => Id::make('appds'),
                    'operator_code' => $operator,
                    'name' => $s['name'] ?? null,
                    'approver_kind' => $s['approver_kind'] ?? ApprovalStage::ROLE,
                    'approver_roles' => $s['approver_roles'] ?? null,
                    'approver_user_ref' => $s['approver_user_ref'] ?? null,
                    'approver_email' => $s['approver_email'] ?? null,
                    'required_approvals' => $s['required_approvals'] ?? 1,
                    'allow_requester' => $s['allow_requester'] ?? false,
                ],
            );
        }
        // Idempotent re-seed: drop any stages beyond the freshly-declared chain length.
        ApprovalStage::query()->where('definition_id', $def->definition_id)->where('sequence', '>', count($stages))->delete();

        return $def;
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
