<?php

namespace App\Foundation\Tenancy;

use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Multi-tenant isolation for operator-scoped models. Applying this trait:
 *  - adds a global scope so EVERY read (lists, route-model binding/show, relations,
 *    cross-module reads) is filtered to the request's operator — no controller can
 *    forget the predicate;
 *  - forces operator_code = the enforced request context on create, so a client can
 *    never plant a row into another tenant via mass assignment.
 *
 * The operator is the server-derived Context value (set by ResolveOperatorContext from
 * the authenticated principal, NOT from client input). Legitimate cross-operator/platform
 * work must opt out explicitly with Model::withoutGlobalScope(OperatorScope-keyed) — there
 * is no implicit escape hatch.
 */
trait BelongsToOperator
{
    public static function bootBelongsToOperator(): void
    {
        static::addGlobalScope('operator', function (Builder $builder): void {
            $model = $builder->getModel();
            $builder->where($model->getTable().'.operator_code', Context::operatorCode());
        });

        static::creating(function (Model $model): void {
            // Never trust a client-supplied operator_code: pin it to the request context.
            $model->setAttribute('operator_code', Context::operatorCode());
        });
    }

    /** Escape hatch for explicit, audited cross-operator/platform reads. */
    public function scopeForAnyOperator(Builder $query): Builder
    {
        return $query->withoutGlobalScope('operator');
    }
}
