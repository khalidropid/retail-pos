<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessException;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\CashRegisterService;
use App\Services\SaleService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use BuildsDomainData;

    public function test_cash_sale_creates_items_stock_payment_and_cash_movement(): void
    {
        $cashier = $this->userWithRole('cashier');
        $session = $this->openRegister($cashier, '10000');
        $a = $this->stocked(10, ['sale_price' => '1500', 'cost_price' => '1000']);
        $b = $this->stocked(5, ['sale_price' => '2000']);

        $sale = $this->sell($cashier, [[$a, 2], [$b, 1]], 'cash', null, ['amount_tendered' => '6000']);

        $this->assertStringStartsWith('INV-', $sale->number);
        $this->assertSame('5000.00', $sale->total);
        $this->assertSame('5000.00', $sale->paid_amount);
        $this->assertSame('0.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertSame('1000.00', $sale->change_given);
        $this->assertSame('8.000', $a->refresh()->stock_quantity);
        $this->assertSame('4.000', $b->refresh()->stock_quantity);
        $this->assertSame('1000.0000', $sale->items->firstWhere('product_id', $a->id)->unit_cost, 'cost snapshot');
        $this->assertSame(2, StockMovement::query()->where('reference_type', $sale->getMorphClass())->where('reference_id', $sale->id)->count());
        $this->assertSame('15000.00', app(CashRegisterService::class)->expectedCash($session));
        $this->assertDatabaseHas('payment_allocations', ['sale_id' => $sale->id, 'amount' => '5000.00']);
    }

    public function test_sale_with_discount_and_tax(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $p = $this->stocked(10, ['sale_price' => '1000', 'tax_rate' => '10']);

        $sale = $this->sell($cashier, [[$p, 2]], 'cash', null, ['invoice_discount' => '200']);

        $this->assertSame('2000.00', $sale->subtotal);
        $this->assertSame('200.00', $sale->discount_amount);
        $this->assertSame('180.00', $sale->tax_amount);
        $this->assertSame('1980.00', $sale->total);
    }

    public function test_discount_above_store_limit_is_rejected(): void
    {
        settings()->set('pos.max_discount_percent', '10');
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $p = $this->stocked(10, ['sale_price' => '1000']);

        $this->expectException(BusinessException::class);
        $this->sell($cashier, [[$p, 1]], 'cash', null, ['invoice_discount' => '200']);
    }

    public function test_cashier_cannot_change_price_but_manager_can(): void
    {
        $p = $this->stocked(10, ['sale_price' => '1000']);
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);

        try {
            $this->sell($cashier, [[$p, 1, ['unit_price' => '500']]]);
            $this->fail('Cashier changed price');
        } catch (AuthorizationException) {
        }

        $manager = $this->userWithRole('manager');
        $this->openRegister($manager);
        $sale = $this->sell($manager, [[$p, 1, ['unit_price' => '800']]]);
        $this->assertSame('800.00', $sale->total);
        $this->assertSame('1000.00', $sale->items->first()->list_price);
    }

    public function test_client_cannot_lower_price_without_sending_price(): void
    {
        // Totals are always recomputed from DB prices.
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $p = $this->stocked(10, ['sale_price' => '1000']);

        $sale = $this->sell($cashier, [[$p, 3]], 'cash', null, ['total' => '1']);
        $this->assertSame('3000.00', $sale->total);
    }

    public function test_insufficient_stock_rolls_back_everything(): void
    {
        $cashier = $this->userWithRole('cashier');
        $session = $this->openRegister($cashier, '0');
        $ok = $this->stocked(10);
        $short = $this->stocked(1);

        try {
            $this->sell($cashier, [[$ok, 2], [$short, 5]]);
            $this->fail('Expected stock error');
        } catch (BusinessException) {
        }

        $this->assertSame('10.000', $ok->refresh()->stock_quantity);
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame('0.00', app(CashRegisterService::class)->expectedCash($session));
    }

    public function test_sale_requires_open_register(): void
    {
        $p = $this->stocked(5);

        $this->expectException(BusinessException::class);
        $this->sell($this->userWithRole('cashier'), [[$p, 1]]);
    }

    public function test_cash_sale_must_be_fully_paid(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $p = $this->stocked(5, ['sale_price' => '1000']);

        $this->expectException(BusinessException::class);
        $this->sell($cashier, [[$p, 1]], 'cash', null, ['paid_amount' => '500']);
    }

    public function test_integer_unit_rejects_fractional_quantity(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $p = $this->stocked(5);

        $this->expectException(BusinessException::class);
        $this->sell($cashier, [[$p, '1.5']]);
    }

    public function test_card_sale_does_not_touch_cash_drawer(): void
    {
        $cashier = $this->userWithRole('cashier');
        $session = $this->openRegister($cashier, '0');
        $p = $this->stocked(5, ['sale_price' => '1000']);

        $this->sell($cashier, [[$p, 1]], 'cash', null, ['payment_method_id' => PaymentMethod::query()->where('code', 'card')->value('id')]);

        $this->assertSame('0.00', app(CashRegisterService::class)->expectedCash($session));
    }

    public function test_cancel_sale_restores_stock_and_refunds_cash(): void
    {
        $manager = $this->userWithRole('manager');
        $session = $this->openRegister($manager, '0');
        $p = $this->stocked(5, ['sale_price' => '1000']);
        $sale = $this->sell($manager, [[$p, 2]]);

        app(SaleService::class)->cancelSale($sale, 'خطأ في الإدخال', $manager);

        $sale->refresh();
        $this->assertSame(SaleStatus::Cancelled, $sale->status);
        $this->assertSame('5.000', $p->refresh()->stock_quantity);
        $this->assertSame('0.00', app(CashRegisterService::class)->expectedCash($session));
        $this->assertDatabaseHas('audit_logs', ['event' => 'sale_cancelled', 'reason' => 'خطأ في الإدخال']);
    }

    public function test_cashier_cannot_cancel_sale(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $sale = $this->sell($cashier, [[$this->stocked(5), 1]]);

        $this->expectException(AuthorizationException::class);
        app(SaleService::class)->cancelSale($sale, 'x', $cashier);
    }

    public function test_sales_cannot_be_deleted(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $sale = $this->sell($cashier, [[$this->stocked(5), 1]]);

        $this->expectException(\LogicException::class);
        $sale->delete();
    }

    public function test_walk_in_cash_sale_does_not_need_customer(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $sale = $this->sell($cashier, [[$this->stocked(5), 1]]);

        $this->assertNull($sale->customer_id);
        $this->assertSame(0, Customer::query()->count());
    }
}
