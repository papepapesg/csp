<?php

namespace Modules\Ilm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ilm\Models\ContactMethod;

/**
 * @mixin ContactMethod
 */
class ContactMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'type' => $this->type,
            'value' => $this->value,
            'isPrimary' => $this->is_primary,
            'verifiedAt' => $this->verified_at?->toIso8601String(),
        ];
    }
}
