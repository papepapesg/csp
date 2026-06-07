<?php

namespace Modules\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;

/** TCK SLA policy row (data-driven SLA catalog). */
class SlaPolicy extends Model
{
    protected $table = 'sla_policy';

    protected $guarded = [];

    /**
     * Resolve response hours for an operator/category/priority. Prefers the most
     * specific deployed rule; falls back to a safe default.
     */
    public static function resolveHours(?string $operator, ?string $category, string $priority): int
    {
        $row = static::query()
            ->where('priority', $priority)
            ->where(fn ($q) => $q->where('operator_code', $operator)->orWhereNull('operator_code'))
            ->where(fn ($q) => $q->where('category', $category)->orWhereNull('category'))
            ->orderByRaw('operator_code IS NULL')   // operator-specific first
            ->orderByRaw('category IS NULL')        // category-specific first
            ->first();

        return $row?->response_hours ?? match ($priority) {
            'URGENT' => 4, 'HIGH' => 8, 'LOW' => 72, default => 24,
        };
    }
}
