<?php

namespace Modules\Ilm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Ilm\Models\Customer;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'operator_code' => 'WIK',
            'type' => 'RES',
            'name' => $this->faker->name(),
            'identification_type_1' => 'NATIONAL_ID',
            'identification_number_1' => (string) $this->faker->numberBetween(10_000_000, 99_999_999),
            'primary_msisdn' => '+2547'.$this->faker->numberBetween(10_000_000, 99_999_999),
            'email' => $this->faker->safeEmail(),
            'preferred_language' => 'en',
            'kyc_status' => Customer::KYC_PENDING,
        ];
    }
}
