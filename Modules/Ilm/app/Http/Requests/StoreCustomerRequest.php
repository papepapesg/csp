<?php

namespace Modules\Ilm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is guarded by permission:customer.create
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:RES,COM'],
            'name' => ['required', 'string', 'max:255'],
            'primary_msisdn' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'identification_type_1' => ['nullable', 'string', 'max:64'],
            'identification_number_1' => ['nullable', 'string', 'max:128'],
            'identification_type_2' => ['nullable', 'string', 'max:64'],
            'identification_number_2' => ['nullable', 'string', 'max:128'],
            'date_of_birth' => ['nullable', 'date'],
            'business_reg_date' => ['nullable', 'date'],
            'preferred_language' => ['nullable', 'string', 'max:8'],
            'operator_code' => ['nullable', 'string', 'max:16'],
        ];
    }
}
