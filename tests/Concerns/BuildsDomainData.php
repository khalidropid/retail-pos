<?php

namespace Tests\Concerns;

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\User;
use App\Services\CashRegisterService;
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

    protected function openRegister(User $user, string|int $opening = 0): CashRegisterSession
    {
        $register = CashRegister::query()->create(['name' => 'صندوق '.uniqid()]);

        return app(CashRegisterService::class)->openRegister($register, $opening, $user);
    }
}
