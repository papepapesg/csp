<?php

namespace Modules\Ilm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'primary_msisdn' => ['sometimes', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'identification_type_1' => ['nullable', 'string', 'max:64'],
            'identification_number_1' => ['nullable', 'string', 'max:128'],
            'identification_type_2' => ['nullable', 'string', 'max:64'],
            'identification_number_2' => ['nullable', 'string', 'max:128'],
            'date_of_birth' => ['nullable', 'date'],
            'preferred_language' => ['nullable', 'string', 'max:8'],
        ];
    }
}
