<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'مورد '.fake()->unique()->numberBetween(1, 99999), 'phone' => '0770'.fake()->numerify('#######'), 'is_active' => true];
    }
}
