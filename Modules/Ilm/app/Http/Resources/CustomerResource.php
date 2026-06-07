<?php

namespace Modules\Ilm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ilm\Models\Customer;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'customerId' => $this->customer_id,
            'operatorCode' => $this->operator_code,
            'type' => $this->type,
            'name' => $this->name,
            'identification' => [
                'type1' => $this->identification_type_1,
                'number1' => $this->identification_number_1,
                'type2' => $this->identification_type_2,
                'number2' => $this->identification_number_2,
            ],
            'dateOfBirth' => $this->date_of_birth?->toDateString(),
            'businessRegDate' => $this->business_reg_date?->toDateString(),
            'primaryMsisdn' => $this->primary_msisdn,
            'email' => $this->email,
            'preferredLanguage' => $this->preferred_language,
            'kycStatus' => $this->kyc_status,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'accounts' => CustomerAccountResource::collection($this->whenLoaded('accounts')),
            'contactMethods' => ContactMethodResource::collection($this->whenLoaded('contactMethods')),
        ];
    }
}
