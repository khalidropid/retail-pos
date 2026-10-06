<?php

namespace Tests\Feature\Receivables;

use App\Filament\Pages\CustomerStatement;
use App\Filament\Pages\Receivables;
use App\Filament\Resources\CustomerPayments\Pages\CreateCustomerPayment;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Services\CustomerPaymentService;
use Livewire\Livewire;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class ReceivablesUiTest extends TestCase
{
    use BuildsDomainData;

    private function customerWithInvoices(): array
    {
        $cashier = $this->userWithRole('cashier');
        $this->openRegister($cashier);
        $customer = Customer::factory()->create(['name' => 'أحمد', 'credit_limit' => '1000000']);
        $s1 = $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '100000']), 1]], 'credit', $customer, ['paid_amount' => 0]);
        $s2 = $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '50000']), 1]], 'credit', $customer, ['paid_amount' => 0]);

        return [$customer, $s1, $s2];
    }

    public function test_record_payment_from_form_with_auto_allocation(): void
    {
        [$customer, $s1] = $this->customerWithInvoices();
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager);

        Livewire::actingAs($manager)->test(CreateCustomerPayment::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'amount' => 120000,
                'payment_method_id' => PaymentMethod::cash()->id,
                'auto_allocate' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('30000.00', $customer->refresh()->balance);
        $this->assertSame('0.00', $s1->refresh()->balance_due);
    }

    public function test_record_payment_with_manual_allocation(): void
    {
        [$customer, $s1, $s2] = $this->customerWithInvoices();
        $manager = $this->userWithRole('manager');

        $component = Livewire::actingAs($manager)->test(CreateCustomerPayment::class)
            ->fillForm(['customer_id' => $customer->id]);

        $rows = $component->get('data.allocations');
        $this->assertCount(2, $rows);
        foreach ($rows as $key => $row) {
            $rows[$key]['amount'] = $row['sale_id'] === $s2->id ? 50000 : 0;
        }

        $component->fillForm([
            'customer_id' => $customer->id,
            'amount' => 50000,
            'payment_method_id' => PaymentMethod::query()->where('code', 'bank_transfer')->value('id'),
            'auto_allocate' => false,
            'allocations' => $rows,
        ])->call('create')->assertHasNoFormErrors();

        $this->assertSame('0.00', $s2->refresh()->balance_due);
        $this->assertSame('100000.00', $s1->refresh()->balance_due);
    }

    public function test_invalid_payment_shows_arabic_error_and_saves_nothing(): void
    {
        [$customer] = $this->customerWithInvoices();
        $manager = $this->userWithRole('manager');

        Livewire::actingAs($manager)->test(CreateCustomerPayment::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'amount' => 999999,
                'payment_method_id' => PaymentMethod::query()->where('code', 'card')->value('id'),
                'auto_allocate' => true,
            ])
            ->call('create')
            ->assertNotified('تعذّر إتمام العملية');

        $this->assertSame(0, CustomerPayment::query()->where('source', 'account')->count());
    }

    public function test_payment_receipt_prints_required_fields(): void
    {
        [$customer] = $this->customerWithInvoices();
        $manager = $this->userWithRole('manager');
        $payment = app(CustomerPaymentService::class)->recordCustomerPayment($customer, '120000',
            PaymentMethod::query()->where('code', 'card')->value('id'), $manager);

        $this->actingAs($manager)->get(route('print.payment-receipt', $payment))->assertOk()
            ->assertSee('إيصال سداد')->assertSee($payment->number)->assertSee('أحمد')
            ->assertSee('الرصيد قبل الدفع')->assertSee('الرصيد بعد الدفع')->assertSee('الفواتير المسددة')
            ->assertSee($manager->name);
    }

    public function test_statement_html_and_pdf(): void
    {
        [$customer] = $this->customerWithInvoices();
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->get(route('print.customer-statement', $customer))->assertOk()
            ->assertSee('كشف حساب عميل')->assertSee('الرصيد السابق')->assertSee('أحمد');

        $pdf = $this->actingAs($manager)->get(route('print.customer-statement', ['customer' => $customer, 'format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_cashier_cannot_see_statements_or_receivables(): void
    {
        [$customer] = $this->customerWithInvoices();
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($cashier)->get(route('print.customer-statement', $customer))->assertForbidden();
        $this->actingAs($cashier)->get(CustomerStatement::getUrl())->assertForbidden();
        $this->actingAs($cashier)->get(Receivables::getUrl())->assertForbidden();
    }

    public function test_statement_and_receivables_pages_render(): void
    {
        [$customer] = $this->customerWithInvoices();
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->get(CustomerStatement::getUrl(['customer' => $customer->id]))->assertOk()
            ->assertSee('أحمد')->assertSee('فاتورة بيع');
        $this->actingAs($manager)->get(Receivables::getUrl())->assertOk()
            ->assertSee('إجمالي ديون العملاء')->assertSee('أحمد');
    }
}
