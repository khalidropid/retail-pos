<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'تصنيف '.fake()->unique()->numberBetween(1, 99999), 'is_active' => true];
    }
}
