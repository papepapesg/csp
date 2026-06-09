<?php

namespace Modules\Rbac\Models;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/** EM-CFG-03 §8.8 immutable RBAC change-audit row. */
class RbacChangeAudit extends Model
{
    public $timestamps = false;

    protected $table = 'rbac_change_audit';

    protected $primaryKey = 'audit_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['before_json' => 'array', 'after_json' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->audit_id ??= Id::make('rba');
            $m->operator_code ??= Context::operatorCode();
        });
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    public static function record(string $changeType, string $targetType, string $targetId, ?array $before, array $after, ?string $actor = null, ?string $reason = null): void
    {
        static::query()->create([
            'change_type' => $changeType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'actor_user_id' => $actor,
            'before_json' => $before,
            'after_json' => $after,
            'reason_code' => $reason,
        ]);
    }
}
