<?php

namespace Tests\Feature\Audit;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Services\Sales\SaleCalculator;
use App\Services\SaleService;
use App\Support\DbConflict;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

/** Regression tests for the findings of the final audit. */
class AuditFixesTest extends TestCase
{
    use BuildsDomainData;

    public function test_failed_immediate_payment_rolls_back_the_whole_receipt(): void
    {
        $admin = $this->userWithRole('admin'); // no open register -> cash payment must fail
        $product = $this->stocked(0);
        $purchase = app(PurchaseService::class)->create(['supplier_id' => Supplier::factory()->create()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]]], $admin);

        try {
            app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 5]],
                $admin, null, ['amount' => '1000', 'payment_method_id' => PaymentMethod::cash()->id]);
            $this->fail('payment without register should fail');
        } catch (BusinessException) {
        }

        $this->assertSame('0.000', $product->refresh()->stock_quantity);
        $this->assertSame(0, PurchaseReceipt::query()->count());
        $this->assertSame('0.00', $purchase->supplier->refresh()->balance);
        $this->assertSame('0.000', $purchase->items->first()->refresh()->received_quantity);
    }

    public function test_return_and_cancel_work_after_product_was_deleted(): void
    {
        $manager = $this->userWithRole('admin');
        $this->openRegister($manager, '100000');
        $product = $this->stocked(2);
        $sale = $this->sell($manager, [[$product, 2]]);
        app(ProductService::class)->delete($product->refresh(), $manager); // stock is 0 -> allowed

        $return = app(ReturnService::class)->returnSale($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]], 'x', $manager);
        $this->assertSame('1.000', $product->refresh()->stock_quantity);

        $other = $this->stocked(1);
        $sale2 = $this->sell($manager, [[$other, 1]]);
        app(ProductService::class)->delete($other->refresh(), $manager);
        app(SaleService::class)->cancelSale($sale2, 'x', $manager);
        $this->assertSame('1.000', $other->refresh()->stock_quantity);
        $this->assertNotNull($return->id);
    }

    public function test_invoice_discount_never_makes_a_line_negative(): void
    {
        foreach (['0.06', '0.07', '4.01', '1.99'] as $discount) {
            $r = SaleCalculator::calculate([
                ['price' => '1', 'quantity' => '1'], ['price' => '1', 'quantity' => '1'],
                ['price' => '1', 'quantity' => '1'], ['price' => '1', 'quantity' => '1'],
                ['price' => '0.01', 'quantity' => '1'],
            ], $discount);

            foreach ($r['lines'] as $line) {
                $this->assertFalse(Money::isNegative($line['total']), "discount {$discount}");
                $this->assertFalse(Money::isNegative($line['discount']), "discount {$discount}");
            }
            $this->assertSame(Money::round($discount), $r['discount']);
            $this->assertSame(Money::sub('4.01', $discount), $r['total']);
        }
    }

    public function test_allocation_is_exact_and_proportional(): void
    {
        $shares = SaleCalculator::allocate('10', ['a' => '100', 'b' => '100', 'c' => '100']);
        $this->assertSame('10.00', Money::add(...array_values($shares)));
        $this->assertSame(['3.34', '3.33', '3.33'], array_values($shares));
    }

    public function test_duplicate_product_in_purchase_order_is_rejected(): void
    {
        $product = $this->stocked(0);

        $this->expectException(BusinessException::class);
        app(PurchaseService::class)->create(['supplier_id' => Supplier::factory()->create()->id, 'items' => [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 5],
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 7],
        ]], $this->userWithRole('admin'));
    }

    public function test_cancelled_sale_payment_status_is_reset(): void
    {
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager);
        $customer = Customer::factory()->create(['credit_limit' => '100000']);
        $sale = $this->sell($manager, [[$this->stocked(5, ['sale_price' => '1000']), 2]], 'credit', $customer, ['paid_amount' => '500']);
        $this->assertSame(PaymentStatus::PartiallyPaid, $sale->payment_status);

        app(SaleService::class)->cancelSale($sale, 'x', $manager);

        $this->assertSame(PaymentStatus::Unpaid, $sale->refresh()->payment_status);
        $this->assertSame('0.00', $customer->refresh()->balance);
    }

    public function test_cashier_only_sees_returns_on_own_invoices(): void
    {
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager, '10000');
        $p = $this->stocked(5);
        $sale = $this->sell($manager, [[$p, 2]]);
        $return = app(ReturnService::class)->returnSale($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]], 'x', $manager);

        $cashier = $this->userWithRole('cashier');
        $this->assertFalse($cashier->can('view', $return));
        $this->actingAs($cashier)->get("/admin/sale-returns/{$return->id}")->assertNotFound();
        $this->assertTrue($manager->can('view', $return));
    }

    public function test_deadlock_is_reported_as_retryable(): void
    {
        $pdo = new \PDOException('deadlock detected');
        $e = new QueryException('pgsql', 'update x', [], $pdo);
        (fn () => $this->code = '40P01')->call($e);

        $this->assertTrue(DbConflict::is($e));
        $this->assertFalse(DbConflict::is(new \RuntimeException('x')));
    }
}
