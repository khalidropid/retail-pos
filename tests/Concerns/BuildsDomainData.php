<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;

trait BuildsDomainData
{
    protected function stocked(string|int $quantity = 100, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);

        if ((float) $quantity > 0) {
            app(InventoryService::class)->recordOpeningStock($product, (string) $quantity, $this->systemUser());
        }

        return $product->refresh();
    }

    protected function systemUser(): User
    {
        return User::query()->where('email', 'system@pos.test')->first()
            ?? tap(User::factory()->create(['email' => 'system@pos.test', 'name' => 'النظام']))->assignRole('admin');
    }
}
