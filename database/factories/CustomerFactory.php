<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'عميل '.fake()->unique()->numberBetween(1, 999999),
            'phone' => '0750'.fake()->unique()->numerify('#######'),
            'credit_limit' => '500000',
            'credit_days' => 30,
            'is_active' => true,
        ];
    }
}
