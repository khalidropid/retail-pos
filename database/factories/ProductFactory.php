<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates products with zero stock. Give them stock through
 * InventoryService (see Tests\Concerns\BuildsDomainData::stocked()).
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'منتج '.fake()->unique()->numberBetween(1, 999999),
            'sku' => 'SKU-'.fake()->unique()->numberBetween(100000, 999999),
            'barcode' => (string) fake()->unique()->ean13(),
            'unit_id' => Unit::query()->value('id') ?? Unit::factory(),
            'cost_price' => '600',
            'sale_price' => '1000',
            'tax_rate' => '0',
            'reorder_level' => '5',
            'min_stock' => '2',
            'track_stock' => true,
            'is_active' => true,
        ];
    }
}
