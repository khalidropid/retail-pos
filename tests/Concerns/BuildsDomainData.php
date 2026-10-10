<?php

namespace Tests\Concerns;

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PaymentAccountType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\InventoryService;
use App\Services\SaleService;

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

    /**
     * Quick sale helper. $lines: [[Product, qty], ...]
     */
    protected function sell(User $cashier, array $lines, string $type = 'cash', ?Customer $customer = null, array $extra = []): Sale
    {
        $items = array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]] + ($l[2] ?? []), $lines);

        return app(SaleService::class)->completeSale(array_merge([
            'items' => $items,
            'sale_type' => $type,
            'customer_id' => $customer?->id,
            'payment_method_id' => PaymentMethod::cash()->id,
        ], $extra), $cashier);
    }

    protected function walletTypeId(string $code = 'jawali'): int
    {
        return PaymentAccountType::query()->where('code', $code)->value('id');
    }

    protected function accountsMethodId(): int
    {
        return PaymentMethod::query()->where('code', 'card')->value('id');
    }

    protected function bankMethodId(): int
    {
        return PaymentMethod::query()->where('code', 'bank_transfer')->value('id');
    }
}
