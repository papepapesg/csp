<?php

namespace Modules\Ilm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
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
            'customer_id' => ['required', 'string', 'exists:customer,customer_id'],
            'service_address' => ['required', 'string', 'max:512'],
            'homepass_id' => ['nullable', 'string', 'max:64'],
            'payment_account_number' => ['nullable', 'string', 'max:64'],
            'service_class_1' => ['nullable', 'string', 'max:32'],
            'service_class_2' => ['nullable', 'string', 'max:32'],
            'service_class_3' => ['nullable', 'string', 'max:32'],
            'franchise_code' => ['nullable', 'string', 'max:32'],
            'account_manager_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
