<?php

namespace App\Foundation\Models;

use App\Foundation\Support\Id;

/**
 * Configures an Eloquent model to use a string, non-incrementing prefixed-ULID
 * primary key (e.g. svc_..., pkg_...). The owning model declares the prefix and
 * (optionally) a custom key column.
 *
 * Usage:
 *   use HasPrefixedId;
 *   protected string $idPrefix = 'svc';
 */
trait HasPrefixedId
{
    public static function bootHasPrefixedId(): void
    {
        static::creating(function ($model) {
            $key = $model->getKeyName();
            if (empty($model->{$key})) {
                $model->{$key} = Id::make($model->idPrefix());
            }
        });
    }

    public function initializeHasPrefixedId(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    public function idPrefix(): string
    {
        return property_exists($this, 'idPrefix') ? $this->idPrefix : 'id';
    }
}
