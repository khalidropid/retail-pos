<?php

namespace Tests\Feature\Receivables;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\CustomerPaymentService;
use App\Services\CustomerStatementService;
use App\Services\ReceivableService;
use App\Services\SaleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class ReceivablesTest extends TestCase
{
    use BuildsDomainData;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = $this->userWithRole('cashier');
        $this->openRegister($this->cashier, '0');
    }

    private function creditInvoice(Customer $customer, string $amount): Sale
    {
        $product = $this->stocked(10, ['sale_price' => $amount]);

        return $this->sell($this->cashier, [[$product, 1]], 'credit', $customer, ['paid_amount' => 0]);
    }

    private function pay(Customer $customer, string $amount, ?array $allocations = null, ?User $user = null): CustomerPayment
    {
        return app(CustomerPaymentService::class)->recordCustomerPayment(
            $customer, $amount, PaymentMethod::cash()->id, $user ?? $this->cashier, $allocations);
    }

    public function test_partial_then_full_payment_updates_invoice_status(): void
    {
        // Spec §9.2: 100,000 -> pay 40,000 -> partially paid -> pay 60,000 -> paid
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $sale = $this->creditInvoice($customer, '100000');
        $this->assertSame(PaymentStatus::Unpaid, $sale->payment_status);

        $this->pay($customer, '40000');
        $sale->refresh();
        $this->assertSame('40000.00', $sale->paid_amount);
        $this->assertSame('60000.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::PartiallyPaid, $sale->payment_status);

        $this->pay($customer, '60000');
        $sale->refresh();
        $this->assertSame('100000.00', $sale->paid_amount);
        $this->assertSame('0.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertSame('0.00', $customer->refresh()->balance);
    }

    public function test_three_partial_payments_on_one_invoice_without_new_invoices(): void
    {
        // Spec §11: 500,000 = 100,000 + 150,000 + 250,000
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $sale = $this->creditInvoice($customer, '500000');

        foreach (['100000', '150000', '250000'] as $amount) {
            $this->pay($customer, $amount);
        }

        $this->assertSame('0.00', $sale->refresh()->balance_due);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(3, PaymentAllocation::query()->where('sale_id', $sale->id)->count());
    }

    public function test_one_payment_distributed_over_several_invoices(): void
    {
        // Spec §12: #1001=100k, #1002=50k, #1003=70k, pay 150k -> 1001 & 1002 paid, 1003 unpaid
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $s1 = $this->creditInvoice($customer, '100000');
        $s2 = $this->creditInvoice($customer, '50000');
        $s3 = $this->creditInvoice($customer, '70000');

        $payment = $this->pay($customer, '150000', [$s1->id => '100000', $s2->id => '50000', $s3->id => '0']);

        $this->assertSame(PaymentStatus::Paid, $s1->refresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $s2->refresh()->payment_status);
        $this->assertSame(PaymentStatus::Unpaid, $s3->refresh()->payment_status);
        $this->assertCount(2, $payment->allocations);
        $this->assertSame('70000.00', $customer->refresh()->balance);
    }

    public function test_fifo_auto_allocation_pays_oldest_due_first(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $s1 = $this->creditInvoice($customer, '100000');
        $s2 = $this->creditInvoice($customer, '50000');

        $this->pay($customer, '120000');

        $this->assertSame(PaymentStatus::Paid, $s1->refresh()->payment_status);
        $this->assertSame('30000.00', $s2->refresh()->balance_due);
    }

    public function test_overpayment_is_rejected(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->creditInvoice($customer, '100000');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('أكبر من إجمالي دين العميل');
        $this->pay($customer, '100001');
    }

    public function test_allocation_above_invoice_balance_is_rejected(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $s1 = $this->creditInvoice($customer, '100000');
        $this->creditInvoice($customer, '50000');

        $this->expectException(BusinessException::class);
        $this->pay($customer, '120000', [$s1->id => '120000']);
    }

    public function test_allocation_sum_must_equal_payment(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $s1 = $this->creditInvoice($customer, '100000');

        $this->expectException(BusinessException::class);
        $this->pay($customer, '50000', [$s1->id => '40000']);
    }

    public function test_cannot_allocate_to_another_customers_invoice(): void
    {
        $a = Customer::factory()->create(['credit_limit' => '1000000']);
        $b = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->creditInvoice($a, '100000');
        $bSale = $this->creditInvoice($b, '100000');

        $this->expectException(BusinessException::class);
        $this->pay($a, '10000', [$bSale->id => '10000']);
    }

    public function test_cash_payment_enters_register_and_receipt_balances_are_stored(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->creditInvoice($customer, '100000');
        $session = app(CashRegisterService::class)->currentSession($this->cashier);

        $payment = $this->pay($customer, '30000');

        $this->assertSame('100000.00', $payment->balance_before);
        $this->assertSame('70000.00', $payment->balance_after);
        $this->assertSame('30000.00', app(CashRegisterService::class)->expectedCash($session));
        $this->assertDatabaseHas('cash_movements', ['type' => 'customer_payment', 'amount' => '30000.00', 'reference_id' => $payment->id]);
    }

    public function test_reversal_restores_debt_invoice_status_and_cash(): void
    {
        $manager = $this->userWithRole('manager');
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $sale = $this->creditInvoice($customer, '100000');
        $payment = $this->pay($customer, '40000');
        $session = app(CashRegisterService::class)->currentSession($this->cashier);

        app(CustomerPaymentService::class)->reversePayment($payment, 'دفعة مسجلة بالخطأ', $this->userWithRole('admin'));

        $this->assertTrue($payment->refresh()->isVoided());
        $this->assertSame('100000.00', $customer->refresh()->balance);
        $this->assertSame(PaymentStatus::Unpaid, $sale->refresh()->payment_status);
        $this->assertSame('0.00', app(CashRegisterService::class)->expectedCash($session));
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment_reversed', 'reason' => 'دفعة مسجلة بالخطأ']);
        $this->assertDatabaseHas('customer_ledger_entries', ['type' => 'payment_reversal', 'debit' => '40000.00']);
    }

    public function test_cashier_cannot_reverse_payment(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->creditInvoice($customer, '100000');
        $payment = $this->pay($customer, '40000');

        $this->expectException(AuthorizationException::class);
        app(CustomerPaymentService::class)->reversePayment($payment, 'x', $this->cashier);
    }

    public function test_payment_cannot_be_reversed_twice_or_deleted(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->creditInvoice($customer, '100000');
        $payment = $this->pay($customer, '40000');
        $admin = $this->userWithRole('admin');
        app(CustomerPaymentService::class)->reversePayment($payment, 'x', $admin);

        try {
            app(CustomerPaymentService::class)->reversePayment($payment, 'x', $admin);
            $this->fail('Double reversal');
        } catch (BusinessException) {
        }

        $this->expectException(\LogicException::class);
        $payment->delete();
    }

    public function test_paid_amount_always_equals_sum_of_active_allocations(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $s1 = $this->creditInvoice($customer, '100000');
        $s2 = $this->creditInvoice($customer, '80000');
        $p1 = $this->pay($customer, '120000');
        $this->pay($customer, '30000');
        app(CustomerPaymentService::class)->reversePayment($p1, 'x', $this->userWithRole('admin'));

        foreach ([$s1, $s2] as $sale) {
            $active = PaymentAllocation::query()->where('sale_id', $sale->id)
                ->whereHas('payment', fn ($q) => $q->where('status', 'active'))->sum('amount');
            $this->assertEquals((float) $active, (float) $sale->refresh()->paid_amount);
        }

        $openSum = Sale::query()->where('customer_id', $customer->id)->outstanding()->sum('balance_due');
        $this->assertEquals((float) $openSum, (float) $customer->refresh()->balance, 'customer balance = sum of open invoice balances');
    }

    public function test_account_statement_running_balance(): void
    {
        // Spec §16 example
        $customer = Customer::factory()->create(['name' => 'محمد', 'credit_limit' => '1000000']);
        Carbon::setTestNow('2026-10-01 10:00');
        $this->creditInvoice($customer, '100000');
        Carbon::setTestNow('2026-10-05 10:00');
        $this->pay($customer, '30000');
        Carbon::setTestNow('2026-10-10 10:00');
        $this->creditInvoice($customer, '50000');
        Carbon::setTestNow('2026-10-15 10:00');
        $this->pay($customer, '50000');
        Carbon::setTestNow();

        $statement = app(CustomerStatementService::class)->statement($customer);
        $this->assertSame(['100000.00', '70000.00', '120000.00', '70000.00'], $statement['entries']->pluck('balance_after')->all());
        $this->assertSame('70000.00', $statement['closing_balance']);

        $filtered = app(CustomerStatementService::class)->statement($customer, '2026-10-06', '2026-10-31');
        $this->assertSame('70000.00', $filtered['opening_balance']);
        $this->assertCount(2, $filtered['entries']);
        $this->assertSame('70000.00', $filtered['closing_balance']);
    }

    public function test_aging_buckets(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '10000000', 'credit_days' => 0]);
        Carbon::setTestNow('2026-06-01 10:00');
        $this->creditInvoice($customer, '100'); // > 90
        Carbon::setTestNow('2026-08-20 10:00');
        $this->creditInvoice($customer, '200'); // 61-90 (as of 2026-10-30 -> 71 days)
        Carbon::setTestNow('2026-10-20 10:00');
        $this->creditInvoice($customer, '300'); // 1-30
        Carbon::setTestNow();

        $aging = app(ReceivableService::class)->aging(Carbon::parse('2026-10-30'));
        $row = $aging['rows']->firstWhere('id', $customer->id);

        $this->assertEquals(100, (float) $row['d90_plus']);
        $this->assertEquals(200, (float) $row['d61_90']);
        $this->assertEquals(300, (float) $row['d0_30']);
        $this->assertEquals(600, (float) $row['total']);
    }

    public function test_customer_summary(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '500000']);
        $this->creditInvoice($customer, '400000');
        $this->pay($customer, '100000');

        $summary = app(CustomerStatementService::class)->summary($customer->refresh());

        $this->assertSame('400000.00', $summary['total_sales']);
        $this->assertSame('100000.00', $summary['total_payments']);
        $this->assertSame('300000.00', $summary['total_debt']);
        $this->assertSame('200000.00', $summary['available_credit']);
    }

    public function test_cancel_blocked_when_account_payment_exists(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $sale = $this->creditInvoice($customer, '100000');
        $this->pay($customer, '10000');

        $this->expectException(BusinessException::class);
        app(SaleService::class)->cancelSale($sale, 'x', $this->userWithRole('admin'));
    }
}
