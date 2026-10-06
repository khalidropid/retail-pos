<?php

namespace Tests\Feature\Returns;

use App\Enums\SaleStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\CustomerPaymentService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class ReturnTest extends TestCase
{
    use BuildsDomainData;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->userWithRole('manager');
        $this->openRegister($this->manager, '100000');
    }

    private function returnLines($sale, array $qtyByProduct, ?int $method = null)
    {
        $lines = [];
        foreach ($sale->items as $item) {
            if (isset($qtyByProduct[$item->product_id])) {
                $lines[] = ['sale_item_id' => $item->id, 'quantity' => $qtyByProduct[$item->product_id]];
            }
        }

        return app(ReturnService::class)->returnSale($sale, $lines, 'عيب مصنعي', $this->manager, $method);
    }

    public function test_credit_sale_return_reduces_debt(): void
    {
        // Spec §21: 100,000 credit, return 30,000 -> debt 70,000
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $p = $this->stocked(20, ['sale_price' => '10000']);
        $sale = $this->sell($this->manager, [[$p, 10]], 'credit', $customer, ['paid_amount' => 0]);

        $return = $this->returnLines($sale, [$p->id => 3]);

        $this->assertSame('30000.00', $return->total);
        $this->assertSame('30000.00', $return->debt_reduction);
        $this->assertSame('0.00', $return->refund_amount);
        $sale->refresh();
        $this->assertSame('70000.00', $sale->balance_due);
        $this->assertSame(SaleStatus::PartiallyReturned, $sale->status);
        $this->assertSame('70000.00', $customer->refresh()->balance);
        $this->assertSame('13.000', $p->refresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'type' => 'sale_return', 'quantity' => '3.000']);
        $this->assertDatabaseHas('customer_ledger_entries', ['type' => 'sale_return', 'credit' => '30000.00', 'balance_after' => '70000.00']);
    }

    public function test_over_return_is_rejected(): void
    {
        // Spec §22: sold 10, returned 4, available 6, return 7 -> rejected
        $p = $this->stocked(20, ['sale_price' => '1000']);
        $sale = $this->sell($this->manager, [[$p, 10]]);
        $this->returnLines($sale, [$p->id => 4]);

        try {
            $this->returnLines($sale->refresh(), [$p->id => 7]);
            $this->fail('Over-return accepted');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('المتاح للإرجاع: 6', $e->getMessage());
        }

        $this->assertSame('4.000', $sale->items()->first()->returned_quantity);
        $this->returnLines($sale->refresh(), [$p->id => 6]);
        $this->assertSame(SaleStatus::Returned, $sale->refresh()->status);
    }

    public function test_cash_sale_full_return_refunds_cash(): void
    {
        $p = $this->stocked(10, ['sale_price' => '2500']);
        $sale = $this->sell($this->manager, [[$p, 2]]);
        $session = app(CashRegisterService::class)->currentSession($this->manager);
        $this->assertSame('105000.00', app(CashRegisterService::class)->expectedCash($session));

        $return = $this->returnLines($sale, [$p->id => 2]);

        $this->assertSame('5000.00', $return->refund_amount);
        $sale->refresh();
        $this->assertSame(SaleStatus::Returned, $sale->status);
        $this->assertSame('0.00', $sale->balance_due);
        $this->assertSame('5000.00', $sale->refunded_amount);
        $this->assertSame('100000.00', app(CashRegisterService::class)->expectedCash($session));
        $this->assertSame('10.000', $p->refresh()->stock_quantity);
    }

    public function test_partially_paid_credit_sale_return_splits_debt_and_refund(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $p = $this->stocked(20, ['sale_price' => '10000']);
        $sale = $this->sell($this->manager, [[$p, 10]], 'credit', $customer, ['paid_amount' => 0]);
        app(CustomerPaymentService::class)->recordCustomerPayment($customer, '80000', PaymentMethod::cash()->id, $this->manager);

        // Remaining debt 20,000; return 5 units = 50,000 -> 20,000 debt cleared, 30,000 refunded.
        $return = $this->returnLines($sale->refresh(), [$p->id => 5]);

        $this->assertSame('20000.00', $return->debt_reduction);
        $this->assertSame('30000.00', $return->refund_amount);
        $this->assertSame('0.00', $sale->refresh()->balance_due);
        $this->assertSame('0.00', $customer->refresh()->balance);
    }

    public function test_return_value_respects_discount_and_tax_and_sums_exactly(): void
    {
        $p = $this->stocked(10, ['sale_price' => '1000', 'tax_rate' => '10']);
        // 3 x 1000 = 3000, invoice discount 100 -> net 2900, tax 290, total 3190
        $sale = $this->sell($this->manager, [[$p, 3]], 'cash', null, ['invoice_discount' => '100']);
        $this->assertSame('3190.00', $sale->total);

        $r1 = $this->returnLines($sale, [$p->id => 1]);
        $r2 = $this->returnLines($sale->refresh(), [$p->id => 1]);
        $r3 = $this->returnLines($sale->refresh(), [$p->id => 1]);

        $this->assertSame('1063.33', $r1->total);
        $this->assertSame('3190.00', Money::add($r1->total, $r2->total, $r3->total), 'returns add up to the invoice exactly');
        $this->assertSame('3190.00', $sale->refresh()->returned_amount);
    }

    public function test_cannot_return_from_cancelled_sale_and_cannot_cancel_after_return(): void
    {
        $p = $this->stocked(10);
        $sale = $this->sell($this->manager, [[$p, 2]]);
        $this->returnLines($sale, [$p->id => 1]);

        try {
            app(SaleService::class)->cancelSale($sale->refresh(), 'x', $this->manager);
            $this->fail('Cancelled a sale that has returns');
        } catch (BusinessException) {
        }

        $other = $this->sell($this->manager, [[$p, 1]]);
        app(SaleService::class)->cancelSale($other, 'x', $this->manager);

        $this->expectException(BusinessException::class);
        $this->returnLines($other->refresh(), [$p->id => 1]);
    }

    public function test_cashier_cannot_create_returns(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $p = $this->stocked(10);
        $sale = $this->sell($cashier, [[$p, 1]]);

        $this->expectException(AuthorizationException::class);
        app(ReturnService::class)->returnSale($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]], 'x', $cashier);
    }

    public function test_item_from_another_invoice_is_rejected(): void
    {
        $p = $this->stocked(10);
        $a = $this->sell($this->manager, [[$p, 1]]);
        $b = $this->sell($this->manager, [[$p, 1]]);

        $this->expectException(BusinessException::class);
        app(ReturnService::class)->returnSale($a, [['sale_item_id' => $b->items->first()->id, 'quantity' => 1]], 'x', $this->manager);
    }
}
