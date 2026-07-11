<?php

namespace App\Foundation\Catalog;

/** Whether a catalog entry is informational, validation-ready, or executable now. */
enum SupportLevel: string
{
    case REFERENCE_ONLY = 'REFERENCE_ONLY';
    case VALIDATION_ONLY = 'VALIDATION_ONLY';
    case EXECUTABLE = 'EXECUTABLE';
    case DEPRECATED = 'DEPRECATED';

    public function isExecutable(): bool
    {
        return $this === self::EXECUTABLE;
    }
}
