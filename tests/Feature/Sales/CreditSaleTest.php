<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class CreditSaleTest extends TestCase
{
    use BuildsDomainData;

    public function test_credit_sale_creates_receivable_with_due_date(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '500000', 'credit_days' => 30]);
        $p = $this->stocked(10, ['sale_price' => '50000']);

        $sale = $this->sell($cashier, [[$p, 2]], 'credit', $customer, ['paid_amount' => 0]);

        $this->assertSame('100000.00', $sale->total);
        $this->assertSame('0.00', $sale->paid_amount);
        $this->assertSame('100000.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::Unpaid, $sale->payment_status);
        $this->assertSame('2026-10-31', $sale->due_date->toDateString());
        $this->assertSame('100000.00', $customer->refresh()->balance);
        $this->assertSame('8.000', $p->refresh()->stock_quantity);
        $this->assertDatabaseHas('customer_ledger_entries', ['customer_id' => $customer->id, 'type' => 'sale', 'debit' => '100000.00', 'balance_after' => '100000.00']);
        Carbon::setTestNow();
    }

    public function test_credit_sale_without_customer_is_rejected(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);

        $this->expectException(BusinessException::class);
        $this->sell($cashier, [[$this->stocked(5), 1]], 'credit', null, ['paid_amount' => 0]);
    }

    public function test_credit_limit_is_enforced_with_clear_arabic_message(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '500000']);
        $p = $this->stocked(100, ['sale_price' => '50000']);

        $this->sell($cashier, [[$p, 8]], 'credit', $customer, ['paid_amount' => 0]); // debt 400,000

        try {
            $this->sell($cashier, [[$p, 3]], 'credit', $customer, ['paid_amount' => 0]); // +150,000
            $this->fail('Credit limit not enforced');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('تجاوز الحد الائتماني', $e->getMessage());
        }

        $this->assertSame('400000.00', $customer->refresh()->balance);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('92.000', $p->refresh()->stock_quantity);
    }

    public function test_override_requires_permission(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000']);
        $p = $this->stocked(100, ['sale_price' => '5000']);

        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        try {
            $this->sell($cashier, [[$p, 1]], 'credit', $customer, ['paid_amount' => 0, 'override_credit_limit' => true]);
            $this->fail('Cashier overrode credit limit');
        } catch (BusinessException) {
        }

        $manager = $this->userWithRole('manager');
        $this->openRegister($manager);
        $sale = $this->sell($manager, [[$p, 1]], 'credit', $customer, ['paid_amount' => 0, 'override_credit_limit' => true]);

        $this->assertTrue($sale->credit_limit_overridden);
        $this->assertDatabaseHas('audit_logs', ['event' => 'credit_limit_overridden', 'user_id' => $manager->id]);
    }

    public function test_override_blocked_when_disabled_in_settings(): void
    {
        settings()->set('credit.allow_override', false);
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager);
        $customer = Customer::factory()->create(['credit_limit' => '1000']);

        $this->expectException(BusinessException::class);
        $this->sell($manager, [[$this->stocked(5, ['sale_price' => '5000']), 1]], 'credit', $customer, ['paid_amount' => 0, 'override_credit_limit' => true]);
    }

    public function test_credit_sale_with_down_payment(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '500000']);
        $p = $this->stocked(10, ['sale_price' => '100000']);

        $sale = $this->sell($cashier, [[$p, 1]], 'credit', $customer, ['paid_amount' => '40000']);

        $this->assertSame('40000.00', $sale->paid_amount);
        $this->assertSame('60000.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::PartiallyPaid, $sale->payment_status);
        $this->assertSame('60000.00', $customer->refresh()->balance);
    }

    public function test_credit_limit_counts_only_unpaid_part(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '50000']);
        $p = $this->stocked(10, ['sale_price' => '100000']);

        $sale = $this->sell($cashier, [[$p, 1]], 'credit', $customer, ['paid_amount' => '60000']);
        $this->assertSame('40000.00', $sale->balance_due);
    }

    public function test_zero_credit_limit_blocks_credit_sales(): void
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '0']);

        $this->expectException(BusinessException::class);
        $this->sell($cashier, [[$this->stocked(5), 1]], 'credit', $customer, ['paid_amount' => 0]);
    }

    public function test_block_on_overdue_setting(): void
    {
        settings()->set('credit.block_on_overdue', true);
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '1000000', 'credit_days' => 0]);
        $p = $this->stocked(10, ['sale_price' => '1000']);
        $this->sell($cashier, [[$p, 1]], 'credit', $customer, ['paid_amount' => 0]);

        Carbon::setTestNow(now()->addDays(2));
        try {
            $this->expectException(BusinessException::class);
            $this->sell($cashier, [[$p, 1]], 'credit', $customer, ['paid_amount' => 0]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_overdue_detection(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['credit_limit' => '1000000', 'credit_days' => 30]);
        $sale = $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '200000']), 1]], 'credit', $customer, ['paid_amount' => '50000']);

        $this->assertFalse($sale->isOverdue());

        Carbon::setTestNow('2026-10-05 09:00:00');
        $sale->refresh();
        $this->assertTrue($sale->isOverdue());
        $this->assertSame(PaymentStatus::Overdue, $sale->displayPaymentStatus());
        $this->assertSame(4, $sale->daysOverdue());
        $this->assertSame(1, Sale::query()->overdue()->count());
        Carbon::setTestNow();
    }
}
